<?php

namespace Tests\Feature\Memberships;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\TillSummary;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 46 — membership fee collection at the till. Before this, RecordFeePayment had zero
 * callers, so unpaid_fee (BLOCK at the counter) was permanently unsatisfied for every member
 * with a fee. These pin the writer, the till action, and the end-to-end unblock.
 */
class FeeCollectionTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value); // STAFF now holds membership.fee.collect + till.open
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    private function memberWithFee(int $feeCents = 2000): Membership
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);

        return Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
        ]);
    }

    public function test_a_cash_fee_payment_attaches_the_till_session_and_moves_no_wallet(): void
    {
        $membership = $this->memberWithFee();
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $payment = (new RecordFeePayment)->handle($membership, 2000, FeePaymentMethod::CASH, ['till_session_id' => $session->id]);

        $this->assertSame($session->id, $payment->till_session_id);
        $this->assertSame(2000, $payment->amount_cents->cents);
        $this->assertSame(0, WalletTransaction::query()->withoutGlobalScopes()->count()); // CASH never touches the wallet
    }

    public function test_a_wallet_fee_payment_posts_the_matching_fee_ledger_movement(): void
    {
        $membership = $this->memberWithFee();
        // Fund the wallet first — a WALLET fee from an empty wallet is (correctly) refused by the debt limit.
        (new RecordWalletTransaction)->handle($membership->member, $this->location, 2000, WalletTransactionType::TOPUP);

        (new RecordFeePayment)->handle($membership, 2000, FeePaymentMethod::WALLET);

        $fee = WalletTransaction::query()->withoutGlobalScopes()
            ->where('type', WalletTransactionType::FEE)->firstOrFail();
        $this->assertSame(-2000, $fee->amount_cents->cents);
        $this->assertSame(0, Wallet::balance($membership->member_id, $this->location->id)); // 2000 top-up − 2000 fee
    }

    public function test_a_partial_instalment_leaves_the_correct_remaining_balance(): void
    {
        $membership = $this->memberWithFee(2000);

        (new RecordFeePayment)->handle($membership, 1200, FeePaymentMethod::CASH);
        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        $this->assertSame(1200, $paid);
        $this->assertGreaterThan($paid, $membership->fee_cents->cents); // still owed €8.00
    }

    public function test_collecting_the_fee_at_the_till_unblocks_dispensing_end_to_end(): void
    {
        $membership = $this->memberWithFee(2000);
        $member = $membership->member;
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $this->operator();

        // Before: unpaid_fee BLOCKs at the counter.
        $before = (new ResolveMemberEligibility)->handle($member, $this->location, 'counter');
        $this->assertContains(__('Cuota de socio pendiente.'), $before->blockingMessages());

        // Collect the full fee at the till.
        Livewire::test(TillSession::class)
            ->set('feeMemberId', $member->id)
            ->set('feeAmount', '20,00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        // After: the block is gone — the member can now be dispensed to.
        $after = (new ResolveMemberEligibility)->handle($member->fresh(), $this->location, 'counter');
        $this->assertNotContains(__('Cuota de socio pendiente.'), $after->blockingMessages());
    }

    public function test_a_cash_fee_flows_into_the_till_reconciliation(): void
    {
        $membership = $this->memberWithFee(2000);
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        (new RecordFeePayment)->handle($membership, 2000, FeePaymentMethod::CASH, ['till_session_id' => $session->id]);

        $this->assertSame(2000, TillSummary::breakdown($session->fresh())['fees_cash']);
    }

    public function test_the_till_action_is_denied_without_the_permission(): void
    {
        $membership = $this->memberWithFee();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        // A user with till.open (to reach the screen) but WITHOUT membership.fee.collect.
        $user = User::factory()->create();
        $user->givePermissionTo(['till.open']);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        Livewire::test(TillSession::class)
            ->set('feeMemberId', $membership->member_id)
            ->set('feeAmount', '20,00')
            ->call('collectFee')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, MembershipFeePayment::query()->count());
    }

    public function test_enrolling_only_sets_the_fee_owed_and_records_no_payment(): void
    {
        // The boundary: setting what's OWED (Members section) is not the same as taking the payment.
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $actor = User::factory()->create();
        $actor->assignRole(Role::OWNER->value); // holds membership.fee.override

        (new EnrolMembership)->handle($member, $this->location, $tier, ['fee_cents' => 2000, 'actor' => $actor]);

        $membership = Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame(2000, $membership->fee_cents->cents);
        $this->assertSame(0, MembershipFeePayment::query()->count()); // no payment created by enrolment
    }

    public function test_the_till_action_is_denied_with_no_open_till(): void
    {
        $membership = $this->memberWithFee();
        $this->operator(); // no till opened

        Livewire::test(TillSession::class)
            ->set('feeMemberId', $membership->member_id)
            ->set('feeAmount', '20,00')
            ->call('collectFee')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, MembershipFeePayment::query()->count());
    }
}
