<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\FindsMembers;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 201 — fee collection lives with the MEMBER, not with the drawer.
 *
 * The caja is about cash: in, out, petty cash, hand over, count, close. `Cobrar cuota` was the one panel on
 * it that began by asking the operator to go and find a person — its own *"Buscar socio"* box, on a screen
 * otherwise not about members at all. Socios does that job and does it better: it shows the record, the
 * outstanding amount and the tier before any money changes hands.
 *
 * Three of the four fee surfaces are CONTEXTUAL — the door, the dispensary and Socios all offer it on a
 * member who is already in front of the operator. Only the till made you search. That distinction, not the
 * count, is why one of four was removed and three were left.
 *
 * **The invariant this branch is really testing** is the one that would silently break the day's
 * reconciliation if it were not true: a CASH fee still lands in the open drawer at that sede, and still
 * shows in the arqueo.
 */
class FeeCollectionLivesInSociosTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 20]);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function memberOwing(int $feeCents = 2500): Member
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

    // --- The invariant ----------------------------------------------------------

    /**
     * A cash fee taken in Socios posts to the open drawer at that sede AND shows in the arqueo.
     *
     * This is the one thing that would break the day's reconciliation silently. Socios resolves the till
     * through prompt 194's single resolver (`SelectTillSession`), so it was already true before this branch —
     * verified before cutting, asserted after.
     */
    public function test_a_cash_fee_in_socios_posts_to_the_open_drawer_and_shows_in_the_arqueo(): void
    {
        $this->operator();
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->memberOwing(2500);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '25.00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        $payment = MembershipFeePayment::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(2500, $payment->amount_cents->cents, 'the real stored amount, in cents');
        $this->assertSame($till->id, $payment->till_session_id, 'it lands in THIS sede\'s open drawer');

        // And the arqueo counts it — the «Cuotas en efectivo» line the caja still shows.
        $breakdown = TillSummary::breakdown($till->fresh());
        $this->assertSame(2500, $breakdown['fees_cash'], 'the arqueo still counts fees taken elsewhere at this sede');
        $this->assertSame(10000 + 2500, $breakdown['expected'], 'and expects them in the drawer');
    }

    /** A CASH fee still needs an open till; a WALLET fee still does not. Unchanged by this branch. */
    public function test_a_cash_fee_is_refused_with_no_open_till_and_a_wallet_fee_is_not(): void
    {
        $this->operator();
        $member = $this->memberOwing(2500);

        // No till open at all.
        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '25.00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, MembershipFeePayment::query()->withoutGlobalScopes()->count());

        // A wallet fee needs FUNDS (it is a wallet spend, and this club allows no debt) — but it does NOT
        // need a till, which is the distinction under test. Top up first so the only variable is the drawer.
        (new RecordWalletTransaction)->handle($member, $this->location, 5000, WalletTransactionType::TOPUP);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '25.00')
            ->set('feeMethod', 'WALLET')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        $this->assertSame(1, MembershipFeePayment::query()->withoutGlobalScopes()->count());
    }

    // --- And the till no longer asks you to find anybody -------------------------

    /**
     * The till renders no fee form and no member lookup, at any permission level.
     *
     * Fails against current `main`, where the panel and its own search box are both there for a
     * `membership.fee.collect` holder.
     */
    public function test_the_till_renders_no_fee_form_and_no_member_lookup(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $this->operator($role);
            (new OpenTill)->handle($this->location, 'POS-'.$role->value, 10000);

            $html = (string) $this->get(route('counter.till'))->assertOk()->getContent();

            $this->assertStringNotContainsString('id="member-lookup"', $html, $role->value.' must not be asked to search for a socio on the caja');
            $this->assertStringNotContainsString('wire:submit="collectFee"', $html, $role->value.' must not get a fee form on the caja');
            $this->assertStringNotContainsString('wire:model="feeAmount"', $html);
        }
    }

    /** The component itself no longer carries the fee state or the handler. */
    public function test_the_till_component_no_longer_collects_fees(): void
    {
        $this->assertFalse(method_exists(TillSession::class, 'collectFee'));
        $this->assertFalse(property_exists(TillSession::class, 'feeMemberId'));
        $this->assertFalse(property_exists(TillSession::class, 'lookup'));

        $uses = class_uses_recursive(TillSession::class);
        $this->assertNotContains(CollectsMembershipFees::class, $uses);
        $this->assertNotContains(FindsMembers::class, $uses);
    }

    /** An operator who reaches for the fee on the caja is told where it went — once, not with a second route. */
    public function test_the_till_tells_a_fee_collector_where_fees_now_live(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = (string) $this->get(route('counter.till'))->assertOk()->getContent();

        $this->assertStringContainsString('data-fee-moved', $html);
        $this->assertStringContainsString(__('Las cuotas se cobran en Socios, donde ves la ficha del socio y lo que debe. El efectivo sigue entrando en esta caja.'), $html);
    }

    /** The other three fee surfaces are untouched — Socios keeps its panel, and it still works. */
    public function test_socios_still_collects_a_fee_exactly_as_before(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->memberOwing(1000);

        Livewire::test(MembershipCounter::class)
            ->assertOk()
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '10.00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        $this->assertSame(1000, MembershipFeePayment::query()->withoutGlobalScopes()->firstOrFail()->amount_cents->cents);
    }
}
