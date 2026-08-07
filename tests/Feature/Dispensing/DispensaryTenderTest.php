<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 74 — the dispensary POS now shares the bar's tender model: the Cash field is what the member
 * HANDED OVER, the screen computes change, and the amount RECORDED is the true contribution. Typing a round
 * note no longer silently refuses the commit. Money is asserted in real stored cents; change never reaches
 * a ledger, so the arqueo must not drift by it.
 */
class DispensaryTenderTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        // €8.37 per gram → 1 g contribution = €8.37, the exact reported scenario.
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 837, 'active' => true,
        ]);
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

    private function batch(): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /** A component with one 1 g line (= €8.37) in the basket, member held, till open. */
    private function withOneGramBasket(Role $role = Role::STAFF): Testable
    {
        $this->operator($role);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->batch();
        $member = $this->member();

        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '1')
            ->call('addLine');
    }

    public function test_over_tender_commits_and_records_the_contribution_not_the_tendered(): void
    {
        $this->withOneGramBasket()
            ->set('cashTendered', '10')                 // hands a €10 note for an €8.37 contribution
            ->assertViewHas('changeDueCents', 163)      // change €1.63 shown BEFORE commit
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $d = Dispensation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(837, $d->total_cents->cents);   // the contribution, in real cents
        $this->assertSame(837, $d->cash_cents->cents);    // recorded cash is the contribution, NOT €10
        $this->assertSame(0, $d->wallet_cents->cents);
    }

    public function test_under_tender_is_refused_with_a_stated_reason(): void
    {
        $this->withOneGramBasket()
            ->set('cashTendered', '5')                  // €5 for an €8.37 contribution
            ->call('commitDispensation')
            ->assertSet('flashType', 'error')
            ->assertSee(__('El efectivo entregado no cubre el total.'));

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_blank_cash_field_still_derives_the_exact_remainder(): void
    {
        // Regression guard: the pre-fix behaviour (blank cash = exact) must still work.
        $this->withOneGramBasket()
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $d = Dispensation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(837, $d->cash_cents->cents);
        $this->assertSame(0, $d->wallet_cents->cents);
    }

    public function test_a_wallet_plus_cash_split_still_commits(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->batch();
        $member = $this->member();
        (new RecordWalletTransaction)->handle($member, $this->location, 500, WalletTransactionType::TOPUP); // €5 credit

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '1')
            ->call('addLine')
            ->set('walletInput', '3')                   // €3 from the wallet
            ->set('cashTendered', '6')                  // €6 handed for the €5.37 cash owed
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $d = Dispensation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(837, $d->total_cents->cents);
        $this->assertSame(300, $d->wallet_cents->cents); // €3 applied
        $this->assertSame(537, $d->cash_cents->cents);   // remainder, not the €6 handed
    }

    public function test_the_arqueo_does_not_drift_by_the_change_given(): void
    {
        $this->withOneGramBasket()
            ->set('cashTendered', '10') // €1.63 change
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $session = TillSession::query()->withoutGlobalScopes()->where('terminal', 'POS-1')->firstOrFail();
        // Float €100 + the €8.37 contribution — NOT the €10 tendered. The change never enters the drawer maths.
        $this->assertSame(10000 + 837, TillSummary::expectedCents($session));
    }

    public function test_over_tender_measures_against_the_overridden_total_with_a_price_override(): void
    {
        // MANAGER holds dispensation.price.override. Override €8.37 → €5.00, hand €10 → change €5.00.
        $this->withOneGramBasket(Role::MANAGER)
            ->set('priceOverrideEuros', '5')
            ->set('priceOverrideReason', 'Producto mohoso')
            ->set('cashTendered', '10')
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $d = Dispensation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(500, $d->total_cents->cents);          // the overridden (charged) total
        $this->assertSame(500, $d->cash_cents->cents);           // cash applied against the OVERRIDDEN total
        $this->assertSame(837, $d->original_total_cents->cents); // the resolved price is kept
    }

    public function test_a_new_flash_visibly_replaces_a_previous_one(): void
    {
        // The prompt-60 "does nothing" class: a stale success flash must be replaced by the new outcome.
        $this->withOneGramBasket()
            ->set('flashMessage', 'Firma capturada')
            ->set('flashType', 'success')
            ->set('cashTendered', '5') // under-tender → a new error flash
            ->call('commitDispensation')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', __('El efectivo entregado no cubre el total.'));
    }
}
