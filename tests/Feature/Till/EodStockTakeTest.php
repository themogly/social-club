<?php

namespace Tests\Feature\Till;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 47 — the end-of-day flower reweigh, wired into the till close. The counting engine
 * (CommitStockTake) was fully built with no UI; this proves the new step scopes to OPEN, WEIGHT-type,
 * TOUCHED batches only, commits through the engine, and gates the cash close. Weight is real centigrams.
 */
class EodStockTakeTest extends TestCase
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
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // holds stock.take + till.open + till.close
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    /** A WEIGHT-type (flower) batch. Touched when remaining_cg < initial_cg. */
    private function flowerBatch(int $initialCg, int $remainingCg, BatchStatus $status = BatchStatus::OPEN): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]); // FLOWER ⇒ WEIGHT

        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => $initialCg, 'remaining_cg' => $remainingCg, 'status' => $status,
        ]);
    }

    private function unitBatch(): Batch
    {
        $genetic = Genetic::factory()->preroll()->create(['organisation_id' => $this->org->id]); // PREROLL ⇒ UNIT

        return Batch::factory()->units(80, 100)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'status' => BatchStatus::OPEN,
        ]);
    }

    /** @return list<string> */
    private function reweighIds(): array
    {
        $this->actingAs($this->manager());

        return Livewire::test(TillSession::class)->instance()->reweighBatches()->pluck('id')->all();
    }

    public function test_an_untouched_batch_is_never_in_the_reweigh_list(): void
    {
        $touched = $this->flowerBatch(100000, 90000);
        $untouched = $this->flowerBatch(100000, 100000); // remaining === initial

        $ids = $this->reweighIds();
        $this->assertContains($touched->id, $ids);
        $this->assertNotContains($untouched->id, $ids);
    }

    public function test_a_unit_type_batch_is_never_in_the_reweigh_list(): void
    {
        $flower = $this->flowerBatch(100000, 90000);
        $preroll = $this->unitBatch(); // touched units, but UNIT-type

        $ids = $this->reweighIds();
        $this->assertContains($flower->id, $ids);
        $this->assertNotContains($preroll->id, $ids);
    }

    public function test_a_closed_or_quarantined_batch_is_never_in_the_reweigh_list(): void
    {
        $open = $this->flowerBatch(100000, 90000);
        $closed = $this->flowerBatch(100000, 90000, BatchStatus::CLOSED);
        $quarantined = $this->flowerBatch(100000, 90000, BatchStatus::QUARANTINED);

        $ids = $this->reweighIds();
        $this->assertContains($open->id, $ids);
        $this->assertNotContains($closed->id, $ids);
        $this->assertNotContains($quarantined->id, $ids);
    }

    public function test_committing_a_count_with_a_variance_posts_one_adjustment_and_updates_remaining(): void
    {
        $this->actingAs($this->manager());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000); // expected 900 g

        Livewire::test(TillSession::class)
            ->assertSet('terminal', 'POS-1')
            ->set('reweighCounts', [$batch->id => '850']) // counted 850 g
            ->call('submitReweigh')
            ->assertSet('reweighDone', true);

        $stockTake = StockTake::query()->firstOrFail();
        $line = StockTakeLine::query()->where('countable_id', $batch->id)->firstOrFail();
        $this->assertSame(-5000, $line->variance_cg->centigrams); // 85000 − 90000 cg

        $adjustments = StockMovement::query()->where('type', 'ADJUSTMENT')->where('stock_take_id', $stockTake->id)->get();
        $this->assertCount(1, $adjustments);
        $this->assertSame(85000, $batch->refresh()->remaining_cg->centigrams); // ledger reconciled to the count
    }

    public function test_committing_a_zero_variance_records_a_line_but_no_adjustment(): void
    {
        $this->actingAs($this->manager());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000); // expected 900 g

        Livewire::test(TillSession::class)
            ->set('reweighCounts', [$batch->id => '900']) // counts EXACTLY the expected
            ->call('submitReweigh')
            ->assertSet('reweighDone', true);

        $this->assertSame(1, StockTakeLine::query()->where('countable_id', $batch->id)->count());
        $this->assertSame(0, StockMovement::query()->where('type', 'ADJUSTMENT')->count());
        $this->assertSame(90000, $batch->refresh()->remaining_cg->centigrams); // unchanged
    }

    public function test_the_reweigh_blocks_the_cash_close_until_it_is_done(): void
    {
        $this->actingAs($this->manager());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000);

        Livewire::test(TillSession::class)
            // Starting the close lands on the flower reweigh, NOT the cash count.
            ->call('startClose')
            ->assertSet('reweighing', true)
            // Trying to count cash first is blocked, explicitly and recoverably.
            ->call('submitCount')
            ->assertSet('flashType', 'warning')
            ->assertSet('countSubmitted', false)
            // Commit the reweigh → the cash count is now unblocked.
            ->set('reweighCounts', [$batch->id => '900'])
            ->call('submitReweigh')
            ->assertSet('reweighDone', true)
            ->assertSet('reweighing', false);
    }

    public function test_the_reweigh_is_skipped_when_it_is_not_the_last_open_till_at_the_location(): void
    {
        $this->actingAs($this->manager());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        (new OpenTill)->handle($this->location, 'POS-2', 10000); // a second terminal still open
        $this->flowerBatch(100000, 90000);

        // Closing POS-1 while POS-2 is open must NOT prompt the reweigh (it fires once, at the last close).
        Livewire::test(TillSession::class)
            ->set('terminal', 'POS-1')
            ->call('startClose')
            ->assertSet('reweighing', false)
            ->assertSet('closing', true);
    }

    public function test_a_user_without_stock_take_cannot_commit_the_reweigh(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // has till.open, NOT stock.take
        $staff->locations()->sync([$this->location->id]);
        CounterOperator::set($staff);
        $this->actingAs($staff);

        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000);

        Livewire::test(TillSession::class)
            ->set('reweighCounts', [$batch->id => '850'])
            ->call('submitReweigh')
            ->assertSet('flashType', 'error')
            ->assertSet('reweighDone', false);

        $this->assertSame(0, StockTake::query()->count());
    }
}
