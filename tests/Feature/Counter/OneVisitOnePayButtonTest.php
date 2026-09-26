<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 263 — one visit, one total, one pay button (a live money bug, reported from the tablet).
 *
 * With bar items added "same visit" the cart showed TWO pay buttons: "Liquidar visita · €23" in the bar block and,
 * larger and at the foot, "Registrar aportación · €20". The big one committed the dispensation ONLY — the drinks
 * stayed in the bar basket, and "Cerrar" discarded them without asking: drinks handed over unpaid and unrecorded,
 * bar stock never taken off. Now there is one button, it settles the whole visit, and nothing unpaid is dropped
 * without a confirmation.
 */
class OneVisitOnePayButtonTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Article $beer;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $operator = User::factory()->create();
        $operator->assignRole(Role::MANAGER->value);
        $operator->locations()->sync([$this->location->id]);
        $this->actingAs($operator);
        CounterOperator::set($operator);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);
        $this->beer = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Cerveza', 'price_cents' => 150, 'stock' => 20, 'active' => true,
        ]);
        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);
    }

    private function visit(bool $flower = true, int $beers = 2): Testable
    {
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id);

        if ($flower) {
            $pos->call('chooseGenetic', $this->genetic->id)->set('weightInput', '2')->call('addLine'); // €20.00
        }

        if ($beers > 0) {
            $pos->call('setCatalogueSource', 'bar');
            for ($i = 0; $i < $beers; $i++) {
                $pos->call('addBarItem', $this->beer->id); // €1.50 each
            }
        }

        return $pos;
    }

    // --- 1. The reported case --------------------------------------------------------------------------------

    public function test_the_big_button_settles_the_whole_visit_not_just_the_dispensation(): void
    {
        $pos = $this->visit()->call('commitDispensation')->assertSet('flashType', 'success');

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count(), 'the drinks were left unpaid and unrecorded');
        $this->assertSame(18, $this->beer->fresh()->stock, 'bar stock never moved');
        $this->assertSame([], $pos->get('barBasket'));
        $this->assertSame(2300, Dispensation::query()->withoutGlobalScopes()->sole()->total_cents->cents
            + Order::query()->withoutGlobalScopes()->sole()->total_cents->cents);
    }

    // --- 2. One button ---------------------------------------------------------------------------------------

    public function test_there_is_exactly_one_pay_button_and_it_carries_the_visit_total(): void
    {
        $html = $this->visit()->html();

        $this->assertStringNotContainsString('data-settle-visit', $html, 'the bar block still has its own pay button');
        $this->assertSame(1, substr_count($html, 'data-commit-action'));
        $this->assertStringContainsString(e(__('Cobrar visita · :total', ['total' => Money::fromCents(2300)->formatted()])), $html);
    }

    // --- 3. Totals agree -------------------------------------------------------------------------------------

    public function test_the_button_the_tender_and_the_header_show_the_same_total(): void
    {
        foreach ([[true, 0, 2000], [false, 2, 300], [true, 2, 2300]] as [$flower, $beers, $cents]) {
            $html = $this->visit($flower, $beers)->html();
            $money = e(Money::fromCents($cents)->formatted());

            preg_match('/data-visit-total[^>]*>\s*([^<]+)</', $html, $header);
            preg_match('/data-cash-due[^>]*>\s*([^<]+)</', $html, $cash);
            $this->assertSame($money, trim($header[1] ?? ''), "header for flower={$flower} beers={$beers}");
            $this->assertSame($money, trim($cash[1] ?? ''), "tender a cobrar for flower={$flower} beers={$beers}");
            $this->assertMatchesRegularExpression('/data-commit-action[\s\S]*?'.preg_quote($money, '/').'/', $html, "button for flower={$flower} beers={$beers}");
        }
    }

    // --- 4. Close asks first ----------------------------------------------------------------------------------

    public function test_closing_the_member_with_unpaid_items_asks_first_and_discarding_writes_nothing(): void
    {
        $pos = $this->visit()->call('clearMember');

        $pos->assertSet('memberId', $this->member->id)->assertSet('confirmDiscard', true);
        $this->assertSame(2, (int) array_sum(array_column($pos->get('barBasket'), 'qty')), 'the two beers are still waiting');
        $pos->assertSee(__('Hay productos sin cobrar. ¿Descartarlos?'));

        $pos->call('clearMember', true)->assertSet('memberId', null)->assertSet('barBasket', [])->assertSet('basket', []);
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_selecting_another_member_with_unpaid_items_asks_first_too(): void
    {
        $other = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subMonth()]);

        $pos = $this->visit(flower: false)->call('selectMember', $other->id);

        // The drinks do NOT silently move onto the next member (they used to: barBasket survived the switch).
        $pos->assertSet('memberId', $this->member->id)->assertSet('confirmDiscard', true);
        $this->assertSame(2, (int) array_sum(array_column($pos->get('barBasket'), 'qty')), 'the two beers are still waiting');
    }

    public function test_closing_with_nothing_unpaid_does_not_ask(): void
    {
        Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id)
            ->call('clearMember')->assertSet('memberId', null)->assertSet('confirmDiscard', false);
    }

    // --- 5. Bar-only visit ------------------------------------------------------------------------------------

    public function test_a_bar_only_visit_settles_through_the_one_button(): void
    {
        $this->visit(flower: false, beers: 1)->call('commitDispensation')->assertSet('flashType', 'success');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(150, Order::query()->withoutGlobalScopes()->sole()->total_cents->cents);
    }

    public function test_the_price_override_still_applies_to_the_dispensation_in_a_combined_visit(): void
    {
        $this->visit()
            ->set('priceOverrideEuros', '15')
            ->set('priceOverrideReason', 'Producto defectuoso')
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $this->assertSame(1500, Dispensation::query()->withoutGlobalScopes()->sole()->total_cents->cents);
        $this->assertSame(300, Order::query()->withoutGlobalScopes()->sole()->total_cents->cents);
    }
}
