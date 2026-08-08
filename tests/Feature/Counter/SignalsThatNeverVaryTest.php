<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
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
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\StockCeiling;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 213 — two things on the sale screen were always on, so neither meant anything.
 *
 * Neither was reported: both came out of reading the screen, and both are judgement calls.
 *
 * **1. Every genetic said "Low stock."** All six on the seeded dispensary — 12.95 g to 32.66 g — badged at
 * once, because `low_stock_threshold_cg` fell back to **5000 cg (50 g)**, a figure chosen for a shop. This
 * product is for clubs under a legal stock ceiling: `stock_ceiling_days` defaults to 5 and
 * `StockCeiling::forLocation()` exists precisely because a Spanish CSC may not hold much. A club holding a
 * lawful few days of one genetic sits under 50 g **permanently**, so the badge was on permanently — furniture
 * rather than a warning, training the operator to read past the one that matters.
 *
 * **2. The price override was a permanently open text box.** Prompt 91 already settled the principle on the
 * till: a consequential action *"must not be the loudest control on a tablet being scrolled mid-shift."* An
 * override that rewrites what a member is charged — recorded, with a reason, because it matters — sat open in
 * the ordinary flow, above the commit, on every transaction.
 */
class SignalsThatNeverVaryTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 50]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);

        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(), 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function genetic(int $remainingCg): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'active' => true]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true, 'low_stock_threshold_cg' => null,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => max($remainingCg, 1), 'remaining_cg' => $remainingCg,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic->fresh();
    }

    // --- 1. The badge that was always on ------------------------------------------------------

    /**
     * A genetic holding a normal amount **for a club under its stock ceiling** is not badged low.
     *
     * Asserted against the ceiling the club actually operates under, not a literal: the holding is what five
     * days of one member's allowance looks like, which is a lawful, ordinary amount for a CSC — and it used
     * to badge, because 50 g is a shop's number. **Fails against `main`.**
     */
    public function test_a_normal_holding_for_a_club_under_its_ceiling_is_not_badged_low(): void
    {
        $this->operator();
        $this->member();

        $dailyLimitCg = (int) StockCeiling::forLocation($this->location)['daily_limit_cg'];
        $ceilingDays = (int) StockCeiling::forLocation($this->location)['ceiling_days'];

        // What a club may lawfully hold of one genetic for one member: five days of their allowance.
        $lawfulHolding = $dailyLimitCg * $ceilingDays;
        $this->assertLessThan(5000, $lawfulHolding, 'precondition: the old 50 g default really was above a lawful holding');

        $genetic = $this->genetic($lawfulHolding);

        $this->assertFalse(
            $genetic->isLowStockAt($lawfulHolding, $this->location->id),
            'a lawful holding for a club under its ceiling is still badged low',
        );
    }

    /** A genetic that is genuinely low still is — less than one member's day left of it. */
    public function test_a_genuinely_low_genetic_is_still_badged(): void
    {
        $this->operator();
        $daily = (int) Settings::get('daily_limit_cg', 300);
        $genetic = $this->genetic(max(1, $daily - 1));

        $this->assertTrue($genetic->isLowStockAt(max(1, $daily - 1), $this->location->id));
    }

    /** The per-sede threshold on `GeneticPrice` still overrides the default, in both directions. */
    public function test_a_per_sede_threshold_still_overrides_the_default(): void
    {
        $this->operator();
        $daily = (int) Settings::get('daily_limit_cg', 300);
        $genetic = $this->genetic($daily * 10);

        $this->assertFalse($genetic->isLowStockAt($daily * 10, $this->location->id));

        $genetic->prices()->withoutGlobalScopes()
            ->where('location_id', $this->location->id)->whereNull('tier_id')
            ->update(['low_stock_threshold_cg' => $daily * 100]);

        $this->assertTrue($genetic->fresh()->isLowStockAt($daily * 10, $this->location->id), 'the per-sede threshold stopped working');
    }

    /** An explicit org setting still wins over the derivation — "0 = derive" is what preserves that. */
    public function test_an_explicit_setting_still_wins(): void
    {
        $this->operator();
        $daily = (int) Settings::get('daily_limit_cg', 300);
        $genetic = $this->genetic($daily * 10);

        $this->assertFalse($genetic->isLowStockAt($daily * 10, $this->location->id));

        Settings::set('low_stock_threshold_cg', $daily * 50, SettingType::INT);
        $this->assertTrue($genetic->fresh()->isLowStockAt($daily * 10, $this->location->id));
    }

    /** 185: the badge is a state; the counter card never becomes a published quantity because of it. */
    public function test_the_badge_is_a_state_not_a_threshold_figure(): void
    {
        $this->operator();
        $member = $this->member();
        $daily = (int) Settings::get('daily_limit_cg', 300);
        $this->genetic(max(1, $daily - 1));

        $html = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->html();

        $this->assertStringNotContainsString('low_stock_threshold', $html, 'the threshold leaked to the screen');
    }

    // --- 2. The override that was always open --------------------------------------------------

    /**
     * The override is **not in the DOM** until it is opened, so it is not in the tab order either — and
     * opening it is a deliberate act with its own control.
     */
    public function test_the_override_is_absent_until_it_is_opened(): void
    {
        $this->operator();
        $member = $this->member();
        $genetic = $this->genetic(500000);

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,00')
            ->call('addLine')
            ->html();

        // The way in is there, and it is one deliberate tap.
        $this->assertStringContainsString('data-price-override-toggle', $html);

        // The fields are inside an Alpine <template>, so they are not rendered, not focusable and not
        // tabbable until the operator opens it — the distinction prompt 91 drew for the till close-out.
        $at = strpos($html, 'data-price-override-toggle');
        $this->assertNotFalse($at);
        $this->assertStringContainsString('<template', substr($html, $at, 600), 'the fields are not behind a disclosure');
        $this->assertStringNotContainsString('placeholder="'.e(__('Nuevo total (€)')).'"', substr($html, 0, $at));
    }

    /**
     * Once opened it behaves exactly as today — permission-gated, reason-required, recorded, with
     * `original_total_cents` set. Asserted against the ROW, because that is what an inspection reads.
     */
    public function test_an_override_still_records_everything_it_did(): void
    {
        $operator = $this->operator();
        $member = $this->member();
        $genetic = $this->genetic(500000);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,00')
            ->call('addLine')
            ->set('priceOverrideEuros', '10,00')
            ->set('priceOverrideReason', 'Producto defectuoso')
            ->set('cashTendered', '10,00')
            ->call('commitDispensation');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->where('member_id', $member->id)->sole();

        $this->assertSame(1000, $dispensation->total_cents->cents, 'the override did not take');
        $this->assertSame(2400, $dispensation->original_total_cents?->cents, 'the original total was not kept');
        $this->assertSame('Producto defectuoso', $dispensation->price_override_reason);
        $this->assertSame($operator->id, $dispensation->operator_id);
    }

    /** Who may override has not changed: a user without the permission is offered nothing. */
    public function test_an_operator_without_the_permission_sees_no_override_at_all(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.use');
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $member = $this->member();
        $genetic = $this->genetic(500000);

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,00')
            ->call('addLine')
            ->html();

        $this->assertStringNotContainsString('data-price-override-toggle', $html);
    }

    /** A commit with no override is unchanged in every respect. */
    public function test_a_commit_with_no_override_is_unchanged(): void
    {
        $this->operator();
        $member = $this->member();
        $genetic = $this->genetic(500000);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,00')
            ->call('addLine')
            ->set('cashTendered', '30,00')
            ->call('commitDispensation');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->where('member_id', $member->id)->sole();

        $this->assertSame(2400, $dispensation->total_cents->cents);
        $this->assertNull($dispensation->original_total_cents, 'a plain commit recorded an override');
        $this->assertNull($dispensation->price_override_reason);
    }
}
