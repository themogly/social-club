<?php

namespace Tests\Feature\Products;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\StockCeiling;
use App\ViewModels\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The premises stock picture — ceiling, on-hand and nearing-expiry — aggregates WEIGHT
 * and UNIT batches TOGETHER, on one gram-equivalent scale, so a location's overall
 * compliance figure is complete.
 */
class UnitStockAggregationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function weightBatch(int $remainingCg, ?string $expiresOn = null): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => $remainingCg, 'status' => BatchStatus::OPEN, 'expires_on' => $expiresOn,
        ]);
    }

    private function unitBatch(int $units, int $gramsPerUnitCg = 70, ?string $expiresOn = null): Batch
    {
        $genetic = Genetic::factory()->preroll($gramsPerUnitCg)->create(['organisation_id' => $this->org->id]);

        return Batch::factory()->units($units)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'status' => BatchStatus::OPEN, 'expires_on' => $expiresOn,
        ]);
    }

    public function test_the_stock_ceiling_on_site_figure_sums_both_kinds(): void
    {
        $this->weightBatch(10000);        // 100 g
        $this->unitBatch(100, 70);        // 100 units × 0.70 g = 70 g = 7000 cg

        $ceiling = StockCeiling::forLocation($this->location);

        $this->assertSame(17000, $ceiling['on_site_cg']); // 10000 + 7000, gram-equivalent
    }

    public function test_the_dashboard_on_hand_figure_sums_both_kinds(): void
    {
        $this->weightBatch(10000);
        $this->unitBatch(100, 70);

        $dashboard = new Dashboard($this->org->id, [$this->location->id], Period::today());

        $this->assertSame(17000, $dashboard->stockOnHandCg());
    }

    public function test_nearing_expiry_counts_both_kinds(): void
    {
        $soon = now()->addDays(10)->toDateString();
        $this->weightBatch(10000, $soon);
        $this->unitBatch(100, 70, $soon);

        $dashboard = new Dashboard($this->org->id, [$this->location->id], Period::today());

        // Both the weight and the unit batch are within the expiry window.
        $this->assertSame(2, $dashboard->expiringBatches());
    }
}
