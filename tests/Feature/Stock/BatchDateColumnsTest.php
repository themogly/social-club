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
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 357 — Ben, on /batches?sort=lote:desc: "date added field". The list had no date column, so a sort by Lote
 * (persisted in the session) could not be undone by a click, and nobody could see when a batch was entered.
 */
class BatchDateColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Location $sede;

    private Batch $older;

    private Batch $newer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $org->id, 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->sede->id]);
        $this->actingAs($owner);

        // ADDED in the opposite order to RECEIVED, so the two columns' sorts can be told apart. Names A (older add) / Z.
        $make = fn (string $name, string $added, string $received): Batch => Batch::factory()->create([
            'organisation_id' => $org->id, 'location_id' => $this->sede->id, 'status' => BatchStatus::OPEN, 'remaining_cg' => 1000,
            'genetic_id' => Genetic::factory()->create(['organisation_id' => $org->id, 'name' => $name])->id,
            'created_at' => $added, 'acquired_or_harvested_on' => $received,
        ]);
        $this->older = $make('Amnesia', '2026-09-01 08:15:00', '2026-10-01');
        $this->newer = $make('Zkittlez', '2026-10-05 18:40:00', '2026-08-01');
    }

    /** @return list<string> */
    private function order(Testable $list): array
    {
        return $list->instance()->getTableRecords()->map(fn (Batch $b): string => (string) $b->getKey())->values()->all();
    }

    public function test_the_list_shows_anadido_with_the_date_and_time_in_the_sedes_timezone(): void
    {
        $list = Livewire::test(ListBatches::class);
        $column = $list->instance()->getTable()->getColumn('created_at');

        $this->assertNotNull($column, 'no «Añadido» column');
        $this->assertSame(__('Añadido'), $column->getLabel());
        $this->assertFalse($column->isToggledHiddenByDefault());
        // 18:40 UTC on 5 Oct is 20:40 in Madrid (summer time).
        $list->assertSee(CarbonImmutable::parse('2026-10-05 18:40:00', 'UTC')->setTimezone('Europe/Madrid')->translatedFormat('d M Y, H:i'));

        $names = collect($list->instance()->getTable()->getColumns())->keys()->values()->all();
        $this->assertSame(array_search('location.name', $names, true) + 1, array_search('created_at', $names, true), '«Añadido» sits right after «Sede»');
    }

    public function test_anadido_sorts_both_ways_and_replaces_a_persisted_sort(): void
    {
        $list = Livewire::test(ListBatches::class)->sortTable('lote', 'desc'); // the sort Ben had, which then sticks
        $this->assertSame([(string) $this->newer->id, (string) $this->older->id], $this->order($list));

        $list->sortTable('created_at', 'asc');
        $this->assertSame([(string) $this->older->id, (string) $this->newer->id], $this->order($list));
        $list->sortTable('created_at', 'desc');
        $this->assertSame([(string) $this->newer->id, (string) $this->older->id], $this->order($list));
    }

    public function test_recibido_exists_sortable_and_hidden_by_default(): void
    {
        $column = Livewire::test(ListBatches::class)->instance()->getTable()->getColumn('acquired_or_harvested_on');

        $this->assertNotNull($column);
        $this->assertSame(__('Recibido'), $column->getLabel());
        $this->assertTrue($column->isSortable());
        $this->assertTrue($column->isToggledHiddenByDefault());
    }

    public function test_the_default_sort_is_still_newest_received_first(): void
    {
        // Received: older batch on 1 Oct, newer batch on 1 Aug → the OLDER-added batch is first by receipt (308).
        $this->assertSame([(string) $this->older->id, (string) $this->newer->id], $this->order(Livewire::test(ListBatches::class)));
    }
}
