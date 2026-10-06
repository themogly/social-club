<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\IntakeBatch;
use App\Enums\Role;
use App\Filament\Resources\Batches\BatchActions;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 340 — Ben, on a batch's page on his phone: "Can't edit the price." *Precio* was only in the list's ⋮ (cut off on
 * an iPhone). Now it is ONE shared action (`BatchActions::price()`) on the list and on the batch's own page, and the page
 * shows the current price with *Cambiar precio*.
 */
class BatchPriceOnThePageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->sede->id]);
        $this->actingAs($owner);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Lemonade Haze']);
        $this->batch = (new IntakeBatch)->handle($genetic, $this->sede, ['grams' => '148', 'cost_per_gram_cents' => 600, 'price_per_gram_cents' => 1200, 'price_per_eighth_cents' => 3800]);
    }

    private function page(): Testable
    {
        return Livewire::test(EditBatch::class, ['record' => $this->batch->getRouteKey()]);
    }

    // --- 1 + 3. On the page --------------------------------------------------------------------------------------------------

    public function test_the_page_shows_the_price_and_changes_it_through_the_same_audited_action(): void
    {
        $this->page()
            ->assertSeeHtml('data-batch-current-price')
            ->assertSee(Money::fromCents(1200)->formatted())->assertSee(Money::fromCents(3800)->formatted())
            ->assertSee(__('Cambiar precio'))
            ->assertActionExists('price')
            ->callAction('price', ['rate_eur' => '13', 'eighth_eur' => '40'])->assertHasNoActionErrors()
            ->assertSee(Money::fromCents(1300)->formatted()); // the page shows the new price after saving

        $this->assertSame([1300, 4000], [$this->batch->fresh()->price_per_gram_cents, $this->batch->fresh()->price_per_eighth_cents]);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.price.updated')->count());
    }

    public function test_below_cost_on_the_page_asks_first_as_on_the_list(): void
    {
        $page = $this->page()->callAction('price', ['rate_eur' => '5'])->assertActionMounted('belowCost');
        $this->assertSame(1200, $this->batch->fresh()->price_per_gram_cents);
        $page->callMountedAction();
        $this->assertSame(500, $this->batch->fresh()->price_per_gram_cents);
        $this->assertTrue((bool) (AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.price.updated')->sole()->after['below_cost'] ?? false));
    }

    public function test_cambiar_precio_opens_the_price_action(): void
    {
        $this->page()->assertSeeHtml('wire:click="mountAction(\'price\')"')->call('mountAction', 'price')->assertActionMounted('price');
    }

    // --- 2. One definition --------------------------------------------------------------------------------------------------

    public function test_the_list_and_the_page_use_the_one_shared_action(): void
    {
        $table = (string) file_get_contents(app_path('Filament/Resources/Batches/Tables/BatchesTable.php'));
        $this->assertStringNotContainsString('function priceAction', $table);
        $this->assertStringContainsString('BatchActions::price()', $table);
        $this->assertStringContainsString('BatchActions::price()', (string) file_get_contents(app_path('Filament/Resources/Batches/Pages/EditBatch.php')));
        $this->assertTrue(method_exists(BatchActions::class, 'price'));

        Livewire::test(ListBatches::class)->callTableAction('price', $this->batch, ['rate_eur' => '12.50'])->assertHasNoTableActionErrors();
        $this->assertSame(1250, $this->batch->fresh()->price_per_gram_cents);
    }

    public function test_the_row_menu_puts_precio_first_and_the_heavy_actions_last(): void
    {
        $table = (string) file_get_contents(app_path('Filament/Resources/Batches/Tables/BatchesTable.php'));
        preg_match('/ActionGroup::make\(\[(.*?)\]\)/s', $table, $group);
        preg_match_all('/(BatchActions::\w+(?=\(\))|self::\w+Action|EditAction)/', $group[1] ?? '', $items);
        // Prompt 354 — *Trasladar* joined the ⋮ (second, after Precio), and Editar moved above the heavy ones, which stay
        // last (344: destructive last).
        $this->assertSame(['BatchActions::price', 'BatchActions::transfer', 'BatchActions::addParts', 'BatchActions::recount', 'EditAction', 'self::adjustAction', 'self::mermaAction', 'self::recallAction'], $items[1]);
    }

    // --- 4 (structure) + 5. The header, and what stays locked ------------------------------------------------------------------

    public function test_the_header_has_precio_after_trasladar_and_the_destructive_actions_last_in_a_group(): void
    {
        $names = array_map(fn ($a) => $a instanceof ActionGroup ? 'group' : $a->getName(), $this->page()->instance()->getCachedHeaderActions());
        $this->assertSame(['backToList', 'transfer', 'price', 'addParts', 'recount', 'group'], $names);
    }

    public function test_location_and_strain_stay_locked_on_edit(): void
    {
        $this->page()->assertFormFieldIsDisabled('location_id')->assertFormFieldIsDisabled('genetic_id');
    }
}
