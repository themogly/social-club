<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\RecordStockMovement;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Article;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StockMovementTest extends TestCase
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
    }

    public function test_intake_converts_grams_to_centigrams_and_records_a_movement(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 12.50]);

        $this->assertSame(1250, $batch->remaining_cg->centigrams);
        $this->assertSame(1250, $batch->initial_cg->centigrams);
        $this->assertDatabaseHas('stock_movements', [
            'stockable_id' => $batch->id, 'type' => StockMovementType::INTAKE->value, 'qty_cg' => 1250,
        ]);
    }

    public function test_adjustment_records_a_signed_movement_with_a_reason(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 10]);

        (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, -325, ['reason' => 'Rotura']);

        $this->assertSame(675, $batch->fresh()->remaining_cg->centigrams);
        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovementType::ADJUSTMENT->value, 'qty_cg' => -325, 'reason' => 'Rotura',
        ]);
    }

    public function test_merma_requires_the_permission(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 10]);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        $this->expectException(AuthorizationException::class);
        (new RecordStockMovement)->handle($batch, StockMovementType::MERMA, -50, ['actor' => $staff, 'reason' => 'Secado']);
    }

    public function test_merma_succeeds_for_a_permitted_user(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 10]);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // has stock.merma

        (new RecordStockMovement)->handle($batch, StockMovementType::MERMA, -50, ['actor' => $manager, 'reason' => 'Secado']);

        $this->assertSame(950, $batch->fresh()->remaining_cg->centigrams);
    }

    public function test_a_batch_cannot_be_oversold(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 1]); // 100 cg
        $recorder = new RecordStockMovement;

        $recorder->handle($batch, StockMovementType::DISPENSE, -60, []); // 40 cg left

        $this->expectException(RuntimeException::class);
        $recorder->handle($batch, StockMovementType::DISPENSE, -60, []); // would be -20
    }

    public function test_articles_use_unit_quantities_independently_of_cannabis_stock(): void
    {
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'stock' => 10]);

        $movement = (new RecordStockMovement)->handle($article, StockMovementType::SALE, -3, []);

        $this->assertSame(7, $article->fresh()->stock);
        $this->assertSame(-3, $movement->qty_units);
        $this->assertNull($movement->qty_cg);
    }
}
