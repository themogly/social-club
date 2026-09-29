<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\IntakeBatch;
use App\Enums\BatchStatus;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Weight;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 302 — moving stock is a visible button on the batch list and on the batch's own page (it hid in the ⋮ menu,
 * though moving stock out of the store is a store batch's main job); and a batch whose strain was deleted is still
 * identifiable (it rendered as a blank row).
 */
class MoveStockButtonTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $store;

    private Location $sede;

    private User $owner;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Storage house', 'kind' => LocationKind::ALMACEN]);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Deadpool']);
        app(ActiveScope::class)->setLocation($this->store->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Storage weed']);
    }

    private function batchAt(Location $where, string $grams = '500'): Batch
    {
        return (new IntakeBatch)->handle($this->genetic, $where, ['grams' => $grams, 'price_per_gram_cents' => 1500]);
    }

    // --- 1–2. The row button ------------------------------------------------------------------------------------------

    public function test_the_move_button_is_its_own_row_action_worded_for_where_the_batch_is(): void
    {
        $inStore = $this->batchAt($this->store);
        $atSede = $this->batchAt($this->sede);

        $list = Livewire::test(ListBatches::class);
        $topLevel = collect($list->instance()->getTable()->getRecordActions());
        $this->assertTrue($topLevel->contains(fn ($a): bool => $a instanceof Action && $a->getName() === 'transfer'), 'the move action is not a row button of its own');
        $inGroup = $topLevel->filter(fn ($a): bool => $a instanceof ActionGroup)
            ->flatMap(fn (ActionGroup $g): array => $g->getActions())->map(fn ($a) => $a->getName());
        $this->assertNotContains('transfer', $inGroup->all(), 'the move action is still inside the ⋮ menu');

        $list->assertTableActionVisible('transfer', $inStore)->assertTableActionHasLabel('transfer', __('Asignar a sede'), $inStore);
        app(ActiveScope::class)->setLocation(null);
        Livewire::test(ListBatches::class)->assertTableActionHasLabel('transfer', __('Trasladar'), $atSede);
    }

    public function test_the_move_button_is_hidden_with_nothing_left_without_the_permission_or_when_quarantined_or_closed(): void
    {
        $empty = $this->batchAt($this->store, '1');
        $empty->forceFill(['remaining_cg' => 0])->save();
        $quarantined = $this->batchAt($this->store);
        $quarantined->forceFill(['status' => BatchStatus::QUARANTINED])->save();
        $closed = $this->batchAt($this->store);
        $closed->forceFill(['status' => BatchStatus::CLOSED])->save();

        Livewire::test(ListBatches::class)
            ->filterTable('stock', 'all') // an empty batch is off the default list since 308
            ->assertTableActionHidden('transfer', $empty)
            ->assertTableActionHidden('transfer', $quarantined)
            ->assertTableActionHidden('transfer', $closed);

        $open = $this->batchAt($this->store);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->store->id, $this->sede->id]);
        $this->setRolePermission(Role::MANAGER, 'stock.transfer', false);
        $this->actingAs($manager->fresh());
        Livewire::test(ListBatches::class)->assertTableActionHidden('transfer', $open);
    }

    // --- 3. The edit page --------------------------------------------------------------------------------------------

    public function test_the_batch_page_moves_part_of_the_stock_with_the_same_action_and_shows_what_is_left(): void
    {
        $batch = $this->batchAt($this->store);

        $page = Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionVisible('transfer')
            ->assertActionHasLabel('transfer', __('Asignar a sede'))
            ->callAction('transfer', ['to_location_id' => $this->sede->id, 'quantity' => '120'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Stock trasladado a :to', ['to' => 'Sede Deadpool']));

        $this->assertSame(38000, $batch->fresh()->remaining_cg->centigrams);
        $child = Batch::query()->withoutGlobalScopes()->where('parent_batch_id', $batch->id)->sole();
        $this->assertSame([$this->sede->id, 12000, $batch->batch_no], [$child->location_id, $child->remaining_cg->centigrams, $child->batch_no]);
        $page->assertSee('data-batch-remaining', false)->assertSeeText(Weight::fromCentigrams(38000)->formatted());
    }

    public function test_moving_everything_from_the_page_shows_the_batch_at_its_new_location(): void
    {
        $batch = $this->batchAt($this->store);

        Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('transfer', ['to_location_id' => $this->sede->id, 'all' => true])
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['location_id' => $this->sede->id]);
    }

    // --- 4. A deleted strain -----------------------------------------------------------------------------------------

    public function test_a_batch_of_a_deleted_strain_shows_its_name_marked_deleted_and_its_type(): void
    {
        $batch = $this->batchAt($this->store);
        $this->genetic->deleteQuietly(); // pre-308 state: deleted while its batch held stock (308 refuses that now; live still has such strains)

        $html = (string) Livewire::test(ListBatches::class)->assertCanSeeTableRecords([$batch])->html();

        $this->assertStringContainsString(__(':name (eliminada)', ['name' => 'Storage weed']), $html);
        $this->assertStringContainsString($this->genetic->product_type->getLabel(), $html);
        $this->assertSame(__(':name (eliminada)', ['name' => 'Storage weed']), $batch->fresh()->displayTitle());
    }

    // --- 5. One definition -------------------------------------------------------------------------------------------

    public function test_the_list_and_the_page_use_the_one_transfer_definition(): void
    {
        $table = (string) file_get_contents(app_path('Filament/Resources/Batches/Tables/BatchesTable.php'));
        $page = (string) file_get_contents(app_path('Filament/Resources/Batches/Pages/EditBatch.php'));

        $this->assertStringContainsString('BatchActions::transfer(', $table);
        $this->assertStringContainsString('BatchActions::transfer(', $page);
        $this->assertStringNotContainsString("Action::make('transfer')", $table, 'the table still has its own copy');
        $this->assertStringNotContainsString("Action::make('transfer')", $page);
    }
}
