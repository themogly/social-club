<?php

namespace Tests\Feature\Stock;

use App\Actions\Pricing\SaveGeneticPrice;
use App\Actions\Stock\RecordStockMovement;
use App\Enums\BatchStatus;
use App\Enums\DashboardAlert;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Livewire\Counter\CounterHome;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\ViewModels\Dashboard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 269 — "Low stock alert doesn't work" (reported from a tester).
 *
 * The "Aviso de stock bajo (g)" typed on a variety's price row reached exactly one place: a small dot in the
 * dispensary picker, visible only once a socio was held. Nothing said "you are running low" anywhere a manager
 * looks — the "Requiere atención" list on the dashboard and the counter hub had no such alert. On top of that a
 * figure typed on a TIER's price row was silently ignored, and the demo seed's 50 g floor over 12–33 g of stock
 * badged every variety permanently, so lowering stock visibly changed nothing.
 */
class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'active' => true]);
        $this->batch = $this->batchAt($this->location, $this->genetic, 3000); // 30 g
    }

    private function batchAt(Location $location, Genetic $genetic, int $cg): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
            'initial_cg' => $cg, 'remaining_cg' => $cg, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    /** The same writer the admin price form calls. */
    private function price(?int $thresholdCg, ?string $tierId = null, ?Location $location = null, ?Genetic $genetic = null): GeneticPrice
    {
        return (new SaveGeneticPrice)->handle($genetic ?? $this->genetic, $location ?? $this->location, $tierId, 1000, $thresholdCg);
    }

    /** @param  list<string>|null  $locationIds */
    private function alertCount(DashboardAlert $alert, ?array $locationIds = null): int
    {
        $alerts = (new Dashboard($this->org->id, $locationIds ?? [$this->location->id], Period::today()))->alerts();

        return (int) (collect($alerts)->firstWhere('key', $alert->value)['count'] ?? 0);
    }

    // --- 1. The tester's sequence --------------------------------------------------------------------------------

    public function test_a_variety_under_its_threshold_raises_an_alert_and_a_restock_clears_it(): void
    {
        $this->price(2000); // 20 g — 30 g on hand, nothing to say
        $this->assertSame(0, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));

        (new RecordStockMovement)->handle($this->batch, StockMovementType::ADJUSTMENT, -1500, ['reason' => 'Recuento']); // 15 g left
        $this->assertSame(1, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));

        (new RecordStockMovement)->handle($this->batch, StockMovementType::ADJUSTMENT, 2000, ['reason' => 'Recuento']); // 35 g
        $this->assertSame(0, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));
    }

    public function test_an_empty_variety_is_gone_not_low(): void
    {
        $this->price(2000);
        (new RecordStockMovement)->handle($this->batch, StockMovementType::ADJUSTMENT, -3000, ['reason' => 'Recuento']);

        $this->assertSame(0, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));
    }

    // --- 2. A figure on a tier's row is no longer ignored -----------------------------------------------------------

    public function test_a_threshold_typed_on_a_tier_row_counts(): void
    {
        $this->price(null); // base row, no figure
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $this->price(5000, $tier->id); // 50 g on the tier row — 30 g on hand

        $this->assertSame(5000, $this->genetic->explicitLowStockThresholdCg($this->location->id));
        $this->assertSame(1, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));
    }

    public function test_the_base_row_figure_still_wins_over_a_tier_row(): void
    {
        $this->price(1000); // base: 10 g
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $this->price(5000, $tier->id);

        $this->assertSame(1000, $this->genetic->explicitLowStockThresholdCg($this->location->id));
        $this->assertSame(0, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));
    }

    // --- 3. Barra y tienda articles ---------------------------------------------------------------------------------

    public function test_an_article_at_its_threshold_raises_its_own_alert(): void
    {
        $this->price(null);
        Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'stock' => 2, 'low_stock_threshold' => 3, 'active' => true,
        ]);
        Article::factory()->create([ // plenty
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'stock' => 20, 'low_stock_threshold' => 3, 'active' => true,
        ]);
        Article::factory()->create([ // retired — not a live concern
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'stock' => 0, 'low_stock_threshold' => 3, 'active' => false,
        ]);
        $deleted = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'stock' => 0, 'low_stock_threshold' => 3, 'active' => true,
        ]);
        $deleted->delete();

        $this->assertSame(1, $this->alertCount(DashboardAlert::ARTICLES_LOW_STOCK));
        $this->assertStringContainsString('filters', DashboardAlert::ARTICLES_LOW_STOCK->panelUrl());
    }

    // --- 4. Where it shows ------------------------------------------------------------------------------------------

    public function test_the_panel_dashboard_and_the_counter_hub_say_it(): void
    {
        $this->price(5000);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $this->actingAs($owner);

        $this->get('/')->assertOk()->assertSee(trans_choice(':count variedad con stock bajo|:count variedades con stock bajo', 1, ['count' => 1]));

        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($owner);
        Livewire::test(CounterHome::class)->assertSee(DashboardAlert::GENETICS_LOW_STOCK->label(1));
    }

    public function test_the_count_does_not_grow_per_variety(): void
    {
        $this->price(5000);
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertGreaterThan(0, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queries(); // warm the Settings memo, so both measurements see the same cache
        $one = $queries();

        foreach (range(1, 8) as $i) {
            $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'active' => true]);
            $this->batchAt($this->location, $genetic, 1000);
            $this->price(5000, genetic: $genetic); // same branch as the first — vary the NUMBER of varieties only
        }

        $this->assertSame($one, $queries(), 'the low-stock count runs a query per variety');
    }

    // --- 5. Denial: another sede's stock is not this sede's alert ---------------------------------------------------

    public function test_another_sedes_low_stock_does_not_reach_this_sedes_alerts(): void
    {
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->price(null); // this sede: 30 g, no figure, never dispensed → thin-history floor (daily allowance) → not low
        $this->batchAt($other, $this->genetic, 500);
        $this->price(5000, location: $other); // the other sede: 5 g under 50 g

        $this->assertSame(0, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK));
        $this->assertSame(1, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK, [$other->id]));
        $this->assertSame(1, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK, [$this->location->id, $other->id]), 'the owner rollup counts it once');

        // And another organisation's stock never reaches this one.
        $foreignOrg = Organisation::factory()->create();
        $foreignSede = Location::factory()->create(['organisation_id' => $foreignOrg->id]);
        $foreign = Genetic::factory()->create(['organisation_id' => $foreignOrg->id, 'active' => true]);
        Batch::factory()->create([
            'organisation_id' => $foreignOrg->id, 'genetic_id' => $foreign->id, 'location_id' => $foreignSede->id,
            'initial_cg' => 100, 'remaining_cg' => 100, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        GeneticPrice::query()->withoutGlobalScopes()->create([
            'organisation_id' => $foreignOrg->id, 'genetic_id' => $foreign->id, 'location_id' => $foreignSede->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'low_stock_threshold_cg' => 5000, 'active' => true,
        ]);
        $this->assertSame(1, $this->alertCount(DashboardAlert::GENETICS_LOW_STOCK, [$this->location->id, $other->id]));
    }
}
