<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\CommitStockTake;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\SelectBatch;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\StockMovementType;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockTake;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\StockCeiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_fefo_picks_the_oldest_open_batch_and_skips_expired(): void
    {
        $newer = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id, 'status' => BatchStatus::OPEN, 'remaining_cg' => 5000, 'acquired_or_harvested_on' => now()->subDays(10)]);
        $older = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id, 'status' => BatchStatus::OPEN, 'remaining_cg' => 5000, 'acquired_or_harvested_on' => now()->subDays(30)]);
        $expired = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id, 'status' => BatchStatus::OPEN, 'remaining_cg' => 5000, 'acquired_or_harvested_on' => now()->subDays(60), 'expires_on' => now()->subDay()]);

        $selector = new SelectBatch;
        $this->assertTrue($selector->fefo($this->genetic, $this->location)->is($older));
        $this->assertFalse($selector->isDispensable($expired));
        $this->assertTrue($selector->isDispensable($newer));
    }

    public function test_low_stock_scope_and_premises_ceiling_alert(): void
    {
        // 4 members with an ACTIVE membership AT THIS sede (prompt 110 counts sede membership, not org total).
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Member::factory()->count(4)->create(['organisation_id' => $this->org->id, 'status' => 'ACTIVE'])
            ->each(fn (Member $m) => Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $m->id,
                'location_id' => $this->location->id, 'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
            ]));
        // ceiling = 4 members × 350 cg × 5 days = 7000 cg.
        (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 50]); // 5000 cg — under ceiling

        $under = StockCeiling::forLocation($this->location);
        $this->assertSame(7000, $under['ceiling_cg']);
        $this->assertFalse($under['exceeded']);

        (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 30]); // +3000 = 8000 cg — over
        $this->assertTrue(StockCeiling::forLocation($this->location)['exceeded']);

        Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'stock' => 5, 'low_stock_threshold' => 10]);
        Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'stock' => 50, 'low_stock_threshold' => 10]);
        $this->assertSame(1, Article::query()->lowStock()->count());
    }

    public function test_a_stock_take_commits_variances_and_reconciles_to_the_count(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->location, ['grams' => 10]); // 1000 cg
        $committer = User::factory()->create();
        $take = StockTake::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id]);

        (new CommitStockTake)->handle($take, [['type' => 'batch', 'id' => $batch->id, 'counted' => 950]], $committer);

        $this->assertSame(950, $batch->fresh()->remaining_cg->centigrams); // reconciles to the count
        $this->assertDatabaseHas('stock_movements', [
            'stockable_id' => $batch->id, 'type' => StockMovementType::ADJUSTMENT->value, 'qty_cg' => -50, 'stock_take_id' => $take->id,
        ]);
        $this->assertDatabaseHas('stock_take_lines', ['stock_take_id' => $take->id, 'variance_cg' => -50]);
    }

    public function test_cross_location_batches_are_out_of_scope(): void
    {
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        $batchOther = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $other->id]);

        app(ActiveScope::class)->setLocation($this->location->id);
        $this->assertNull(Batch::find($batchOther->id)); // B's batch invisible from A
    }
}
