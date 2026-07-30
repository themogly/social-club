<?php

namespace Tests\Feature\Products;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Exactly ONE of each column pair (price_per_gram/unit, initial_cg/units,
 * remaining_cg/units, qty_cg/units) is populated per row, driven by the genetic's
 * unit_type and enforced by the model saving guards — not by convention. A UNIT-type
 * batch moves stock in qty_units, never qty_cg (the prompt-21 corrected rule).
 */
class OneOfTwoColumnTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $weight;

    private Genetic $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->weight = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $this->unit = Genetic::factory()->preroll(70)->create(['organisation_id' => $this->org->id]);
    }

    // --- GeneticPrice ---------------------------------------------------------------

    public function test_a_weight_genetic_price_must_use_per_gram_only(): void
    {
        // The happy path (per gram) saves.
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->weight->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 900,
        ]);

        // A per-unit price on a WEIGHT genetic is refused.
        $this->expectException(RuntimeException::class);
        GeneticPrice::factory()->perUnit(800)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->weight->id,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_a_unit_genetic_price_must_use_per_unit_only(): void
    {
        GeneticPrice::factory()->perUnit(800)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->unit->id,
            'location_id' => $this->location->id,
        ]);

        $this->expectException(RuntimeException::class);
        GeneticPrice::factory()->create([ // per gram on a UNIT genetic
            'organisation_id' => $this->org->id, 'genetic_id' => $this->unit->id,
            'location_id' => $this->location->id, 'price_per_gram_cents' => 900,
        ]);
    }

    // --- Batch ----------------------------------------------------------------------

    public function test_a_weight_batch_must_use_centigrams_only(): void
    {
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->weight->id,
            'location_id' => $this->location->id, 'initial_cg' => 10000, 'remaining_cg' => 10000,
        ]);

        // Units on a WEIGHT genetic's batch are refused.
        $this->expectException(RuntimeException::class);
        Batch::factory()->units(50)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->weight->id,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_a_unit_batch_must_use_units_only(): void
    {
        Batch::factory()->units(50)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->unit->id,
            'location_id' => $this->location->id,
        ]);

        // Centigrams on a UNIT genetic's batch are refused.
        $this->expectException(RuntimeException::class);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->unit->id,
            'location_id' => $this->location->id, 'initial_cg' => 10000, 'remaining_cg' => 10000,
        ]);
    }

    // --- StockMovement --------------------------------------------------------------

    public function test_a_stock_movement_needs_exactly_one_quantity(): void
    {
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->weight->id,
            'location_id' => $this->location->id, 'initial_cg' => 10000, 'remaining_cg' => 10000,
        ]);

        // Neither quantity → refused.
        try {
            StockMovement::create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'stockable_type' => $batch->getMorphClass(), 'stockable_id' => $batch->id,
                'type' => StockMovementType::ADJUSTMENT,
            ]);
            $this->fail('A movement with no quantity must be refused.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        // Both quantities → refused.
        $this->expectException(RuntimeException::class);
        StockMovement::create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'stockable_type' => $batch->getMorphClass(), 'stockable_id' => $batch->id,
            'qty_cg' => 100, 'qty_units' => 5, 'type' => StockMovementType::ADJUSTMENT,
        ]);
    }

    public function test_a_movement_against_a_unit_batch_writes_qty_units_not_qty_cg(): void
    {
        $batch = Batch::factory()->units(50)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->unit->id,
            'location_id' => $this->location->id,
        ]);

        // A BATCH-linked movement — proving the corrected rule keys off unit_type, not batch-vs-article.
        $movement = (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, 5, ['reason' => 'Recount']);

        $this->assertSame(5, $movement->qty_units);
        $this->assertNull($movement->qty_cg);
        $this->assertSame($batch->getMorphClass(), $movement->stockable_type);
        $this->assertSame(55, $batch->fresh()->remaining_units);
        $this->assertNull($batch->fresh()->remaining_cg);
    }

    public function test_a_unit_batch_cannot_be_oversold(): void
    {
        $batch = Batch::factory()->units(3)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->unit->id,
            'location_id' => $this->location->id,
        ]);

        $this->expectException(RuntimeException::class);
        (new RecordStockMovement)->handle($batch, StockMovementType::DISPENSE, -4, []); // only 3 units
    }
}
