<?php

namespace Tests\Feature\Stock;

use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 308 — *Lotes* is the working list: what is in stock, newest received first. Empty batches are one filter away
 * (*Existencias*: Con existencias · Vacíos · Todos), a hint says how many are hidden, and the choice sticks for the session.
 * A quarantined batch that still holds stock is IN stock — hiding stock that is there would be the wrong way round.
 */
class BatchListStockFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $this->actingAs($owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
    }

    private function batch(int $cg, ?string $received, BatchStatus $status = BatchStatus::OPEN, ?string $created = null): Batch
    {
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => $cg, 'acquired_or_harvested_on' => $received, 'status' => $status,
        ]);
        if ($created !== null) {
            $batch->forceFill(['created_at' => $created])->saveQuietly();
        }

        return $batch;
    }

    public function test_the_list_shows_what_is_in_stock_newest_received_first(): void
    {
        $old = $this->batch(5000, '2026-06-01');
        $newest = $this->batch(3000, '2026-09-20');
        $quarantined = $this->batch(2000, '2026-08-01', BatchStatus::QUARANTINED);
        $emptyA = $this->batch(0, '2026-09-25');
        $emptyB = $this->batch(0, '2026-05-01', BatchStatus::CLOSED);

        Livewire::test(ListBatches::class)
            ->assertCanSeeTableRecords([$newest, $quarantined, $old], inOrder: true)
            ->assertCanNotSeeTableRecords([$emptyA, $emptyB])
            ->assertSee(trans_choice('Se oculta :count lote vacío|Se ocultan :count lotes vacíos', 2, ['count' => 2]))
            ->filterTable('stock', 'all')
            ->assertCanSeeTableRecords([$emptyA, $newest, $quarantined, $old, $emptyB], inOrder: true)
            ->assertDontSee(trans_choice('Se oculta :count lote vacío|Se ocultan :count lotes vacíos', 2, ['count' => 2]))
            ->filterTable('stock', 'empty')
            ->assertCanSeeTableRecords([$emptyA, $emptyB], inOrder: true)
            ->assertCanNotSeeTableRecords([$newest, $quarantined, $old]);
    }

    public function test_a_batch_with_no_received_date_sorts_by_when_it_was_created(): void
    {
        $dated = $this->batch(1000, '2026-09-01', created: '2026-09-01 10:00:00');
        $undated = $this->batch(1000, null, created: '2026-09-15 10:00:00');
        $older = $this->batch(1000, '2026-08-01', created: '2026-08-01 10:00:00');

        Livewire::test(ListBatches::class)->assertCanSeeTableRecords([$undated, $dated, $older], inOrder: true);
    }

    public function test_the_hint_switches_the_list_to_every_batch(): void
    {
        $empty = $this->batch(0, '2026-09-25');
        $this->batch(1000, '2026-09-01');

        Livewire::test(ListBatches::class)
            ->assertCanNotSeeTableRecords([$empty])
            ->assertSeeHtml('data-show-empty-batches')
            ->set('tableFilters.stock.value', 'all')
            ->assertCanSeeTableRecords([$empty]);
    }

    public function test_the_filter_and_the_sort_stick_for_the_session(): void
    {
        $empty = $this->batch(0, '2026-09-25');
        $this->batch(1000, '2026-09-01');

        Livewire::test(ListBatches::class)->filterTable('stock', 'all')->sortTable('expires_on', 'asc');

        Livewire::test(ListBatches::class)
            ->assertSet('tableFilters.stock.value', 'all')
            ->assertSet('tableSort', 'expires_on:asc')
            ->assertCanSeeTableRecords([$empty]);
    }
}
