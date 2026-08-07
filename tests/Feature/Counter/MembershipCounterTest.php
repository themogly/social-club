<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 127 — the Socios counter tab collects a membership fee wherever the member is standing, through the
 * SAME shared concern as the till screen (RecordFeePayment, the single writer). A cash fee still reconciles
 * against the open drawer; a wallet fee needs no till; a cash fee with no till is refused.
 */
class MembershipCounterTest extends TestCase
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

    private function operator(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    /** A member with an ACTIVE membership carrying an unpaid fee at this sede. */
    private function memberOwing(int $feeCents = 2000): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
        ]);

        return $member;
    }

    private function till(User $operator): TillSession
    {
        return (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $operator->id]);
    }

    public function test_a_cash_fee_from_the_tab_records_the_payment_and_moves_the_drawer(): void
    {
        $operator = $this->operator();
        $till = $this->till($operator);
        $member = $this->memberOwing(2000);
        $expectedBefore = TillSummary::expectedCents($till->fresh());

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '20,00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        $payment = MembershipFeePayment::query()->firstOrFail();
        $this->assertSame(2000, $payment->amount_cents->cents);
        $this->assertSame(FeePaymentMethod::CASH, $payment->method);
        $this->assertSame($till->id, $payment->till_session_id);

        // The regression that matters most: the cash fee moved the drawer's expected cash by exactly €20.
        $this->assertSame($expectedBefore + 2000, TillSummary::expectedCents($till->fresh()));
    }

    public function test_a_cash_fee_with_no_open_till_is_refused(): void
    {
        $this->operator();
        $member = $this->memberOwing(2000);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '20,00')->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, MembershipFeePayment::query()->count());
    }

    public function test_a_wallet_fee_needs_no_open_till(): void
    {
        $this->operator();
        $member = $this->memberOwing(2000);
        // Fund the wallet first — a WALLET fee from an empty wallet is (correctly) refused by the debt limit.
        (new RecordWalletTransaction)->handle($member, $this->location, 2000, WalletTransactionType::TOPUP);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '20,00')->set('feeMethod', 'WALLET')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        $this->assertSame(FeePaymentMethod::WALLET, MembershipFeePayment::query()->firstOrFail()->method);
    }

    public function test_a_partial_fee_reports_what_remains(): void
    {
        $operator = $this->operator();
        $this->till($operator);
        $member = $this->memberOwing(2000);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '5,00')->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success')
            // Prompt 202 — the confirmation names the amount TAKEN as well as what is left, because
            // `feeAmount` has just been reset and the operator can no longer read it off the form.
            ->assertSee(__('Cuota cobrada: :amount. Pendiente: :remaining', [
                'amount' => Money::fromCents(500)->formatted(),
                'remaining' => Money::fromCents(1500)->formatted(),
            ]));
    }

    public function test_collecting_the_fee_clears_the_doors_unpaid_fee_verdict(): void
    {
        $operator = $this->operator();
        $this->till($operator);
        $member = $this->memberOwing(2000);

        // Before: the door flags the unpaid fee.
        $before = collect((new ResolveMemberEligibility)->handle($member, $this->location, 'door')->rules)->firstWhere('rule', 'unpaid_fee');
        $this->assertFalse($before['satisfied']);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '20,00')->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        // After: paid in full → the door no longer flags it.
        $after = collect((new ResolveMemberEligibility)->handle($member->fresh(), $this->location, 'door')->rules)->firstWhere('rule', 'unpaid_fee');
        $this->assertTrue($after['satisfied']);
    }

    public function test_a_user_without_the_permission_cannot_open_the_tab(): void
    {
        // A user lacking membership.fee.collect (no role grants) is refused the route.
        $user = User::factory()->create();
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->get(route('counter.members'))->assertForbidden();
    }
}
