<?php

namespace Tests\Feature\Pricing;

use App\Enums\BatchStatus;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Pages\PreciosSede;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 382 §4 — *Precios de la sede*: every open batch with stock at a sede, its three lists in one screen. Quick fills fill
 * the cells; «Guardar todo» writes only the changed batches, one audit row each with before and after.
 */
class SedePricesScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Location $norte;

    private User $owner;

    private Batch $amnesia;

    private Batch $haze;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id, $this->norte->id]);
        $this->actingAs($this->owner);
        $this->amnesia = $this->batch('Amnesia', ['price_per_gram_cents' => 1000, 'price_per_eighth_cents' => 3000]);
        $this->haze = $this->batch('Haze', ['price_per_gram_cents' => 900, 'local_price_per_gram_cents' => 750]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function batch(string $strain, array $attributes, ?Location $at = null, ?Genetic $genetic = null): Batch
    {
        $genetic ??= Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $strain]);

        return Batch::factory()->create($attributes + ['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => ($at ?? $this->sede)->id,
            'remaining_cg' => 5000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'cost_per_gram_cents' => 300]);
    }

    // --- Save -------------------------------------------------------------------------------------------------------------------

    public function test_save_writes_only_the_changed_batches_with_one_audit_row_each_before_and_after(): void
    {
        Livewire::test(PreciosSede::class)
            ->assertSee('Amnesia')->assertSee('Haze')
            ->assertSet('prices.'.$this->amnesia->id.'.price_per_gram_cents', '10.00')
            ->set('prices.'.$this->amnesia->id.'.local_price_per_gram_cents', '8.80')
            ->set('prices.'.$this->amnesia->id.'.staff_price_per_eighth_cents', '24.50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('1 lote actualizado');

        $this->assertSame(880, (int) $this->amnesia->fresh()->getRawOriginal('local_price_per_gram_cents'));
        $this->assertSame(2450, (int) $this->amnesia->fresh()->getRawOriginal('staff_price_per_eighth_cents'));
        $audits = AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.prices_updated')->get();
        $this->assertCount(1, $audits, 'Haze did not change: not written, not audited');
        $this->assertSame((string) $this->amnesia->id, (string) $audits[0]->auditable_id);
        $this->assertSame(['local_price_per_gram_cents' => null, 'staff_price_per_eighth_cents' => null], $audits[0]->before);
        $this->assertSame(['local_price_per_gram_cents' => 880, 'staff_price_per_eighth_cents' => 2450], array_intersect_key($audits[0]->after, $audits[0]->before));
    }

    public function test_a_standard_price_cannot_be_emptied_and_a_bad_number_is_refused_and_nothing_is_written(): void
    {
        Livewire::test(PreciosSede::class)
            ->set('prices.'.$this->amnesia->id.'.price_per_gram_cents', '')
            ->set('prices.'.$this->haze->id.'.local_price_per_gram_cents', 'abc')
            ->call('save')
            ->assertHasErrors(['prices.'.$this->amnesia->id.'.price_per_gram_cents', 'prices.'.$this->haze->id.'.local_price_per_gram_cents']);

        $this->assertSame(1000, (int) $this->amnesia->fresh()->getRawOriginal('price_per_gram_cents'));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.prices_updated')->count());
    }

    // --- Defaults and the below-cost warning --------------------------------------------------------------------------------

    public function test_a_blank_cell_shows_its_default_in_grey_and_a_cell_below_cost_warns(): void
    {
        Livewire::test(PreciosSede::class)
            ->assertSeeHtml('placeholder="8.00 · −20%"')                 // Amnesia Local / Personal per gram, blank
            ->assertSeeHtml('placeholder="24.00 · −20%"')                // Amnesia 3.5 g
            ->assertDontSeeHtml('data-price-below-cost')
            ->set('prices.'.$this->haze->id.'.staff_price_per_gram_cents', '2.50')   // the cost is 3.00
            ->assertSeeHtml('data-price-below-cost')
            ->assertSee('Por debajo del coste ('.Money::fromCents(300)->formatted().')')
            ->set('onlyBelowCost', true)
            ->assertSee('Haze')->assertDontSee('Amnesia');
    }

    // --- Quick fills --------------------------------------------------------------------------------------------------------

    public function test_local_equals_standard_less_ten_percent_fills_the_cells_and_saves_nothing(): void
    {
        Livewire::test(PreciosSede::class)
            ->set('fillPct', '10')
            ->call('fillFromStandard', 'LOCAL')
            ->assertSet('prices.'.$this->amnesia->id.'.local_price_per_gram_cents', '9.00')
            ->assertSet('prices.'.$this->amnesia->id.'.local_price_per_eighth_cents', '27.00')
            ->assertSet('prices.'.$this->haze->id.'.local_price_per_gram_cents', '8.10')
            ->assertSet('prices.'.$this->haze->id.'.local_price_per_eighth_cents', '')   // no standard 3.5 g, no Local 3.5 g
            ->assertSeeHtml('data-prices-dirty');

        $this->assertNull($this->amnesia->fresh()->getRawOriginal('local_price_per_gram_cents'), 'a quick fill saves nothing');
    }

    public function test_a_quick_fill_applies_to_the_selected_rows_only_and_clear_returns_them_to_the_defaults(): void
    {
        Livewire::test(PreciosSede::class)
            ->set('selected', [$this->amnesia->id])
            ->call('fillFromStandard', 'STAFF')
            ->assertSet('prices.'.$this->amnesia->id.'.staff_price_per_gram_cents', '9.00')
            ->assertSet('prices.'.$this->haze->id.'.staff_price_per_gram_cents', '')
            ->set('selected', [])
            ->call('clearLists')
            ->assertSet('prices.'.$this->amnesia->id.'.staff_price_per_gram_cents', '')
            ->assertSet('prices.'.$this->haze->id.'.local_price_per_gram_cents', '');
    }

    public function test_copy_from_another_sede_takes_that_sedes_current_batch_for_the_same_strain(): void
    {
        $this->batch('Amnesia', ['price_per_gram_cents' => 1100, 'price_per_eighth_cents' => 3300, 'local_price_per_gram_cents' => 950], $this->norte, $this->amnesia->genetic);

        Livewire::test(PreciosSede::class)
            ->set('copyFrom', $this->norte->id)
            ->call('copyFromSede')
            ->assertSet('prices.'.$this->amnesia->id.'.price_per_gram_cents', '11.00')
            ->assertSet('prices.'.$this->amnesia->id.'.local_price_per_gram_cents', '9.50')
            ->assertSet('prices.'.$this->amnesia->id.'.staff_price_per_gram_cents', '')
            ->assertSet('prices.'.$this->haze->id.'.price_per_gram_cents', '9.00'); // Norte has no Haze: untouched
    }

    // --- Rows ------------------------------------------------------------------------------------------------------------

    public function test_only_open_batches_with_stock_at_the_chosen_sede_and_unit_products_price_per_unit(): void
    {
        $this->batch('Agotada', ['price_per_gram_cents' => 800, 'remaining_cg' => 0]);
        $this->batch('Cuarentena', ['price_per_gram_cents' => 800, 'status' => BatchStatus::QUARANTINED]);
        $this->batch('Norteña', ['price_per_gram_cents' => 800], $this->norte);
        $edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Gominola', 'product_type' => 'EDIBLE', 'unit_type' => 'UNIT', 'grams_per_unit_cg' => 7]);
        $gummy = Batch::factory()->units(20, 20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $edible->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'price_per_unit_cents' => 400]);

        Livewire::test(PreciosSede::class)
            ->assertDontSee('Agotada')->assertDontSee('Cuarentena')->assertDontSee('Norteña')
            ->assertSee('Gominola')
            ->assertSet('prices.'.$gummy->id.'.price_per_unit_cents', '4.00')
            ->assertSeeHtml('placeholder="3.20 · −20%"')
            ->set('sede', $this->norte->id)
            ->assertSee('Norteña')->assertDontSee('Amnesia');
    }

    // --- 383: cards name their boxes; the store comes last -----------------------------------------------------------------

    public function test_each_card_box_is_labelled_and_the_cells_send_when_left(): void
    {
        Livewire::test(PreciosSede::class)
            ->assertSeeHtml('data-price-caption')
            ->assertSeeHtml('wire:model.live.blur="prices.'.$this->amnesia->id.'.local_price_per_gram_cents"')
            ->assertDontSeeHtml('wire:model.blur=');
    }

    public function test_with_all_sedes_in_the_topbar_it_opens_on_a_sede_and_lists_the_store_last_saying_why(): void
    {
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        $this->owner->locations()->sync([$this->sede->id, $this->norte->id, $store->id]);
        app(ActiveScope::class)->setLocation(null);

        $page = Livewire::test(PreciosSede::class)->assertSet('sede', $this->sede->id);
        $options = $page->instance()->sedeOptions();
        $this->assertSame([$this->sede->id, $this->norte->id, $store->id], array_keys($options));
        $this->assertSame('Almacén (los traspasos heredan estos precios)', $options[$store->id]);
    }

    // --- Who ---------------------------------------------------------------------------------------------------------------

    public function test_a_manager_sees_only_their_sedes_and_cannot_reach_another(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $this->actingAs($manager);
        $this->batch('Norteña', ['price_per_gram_cents' => 800], $this->norte);

        $page = Livewire::test(PreciosSede::class);
        $this->assertSame([$this->sede->id], array_keys($page->instance()->sedeOptions()));
        $page->assertSee('Amnesia');

        Livewire::withQueryParams(['sede' => $this->norte->id])->test(PreciosSede::class)
            ->assertSet('sede', $this->sede->id)->assertDontSee('Norteña');   // falls back to their own sede
        Livewire::test(PreciosSede::class)->set('sede', $this->norte->id)->assertForbidden();
    }

    public function test_staff_are_refused(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->sede->id]);

        $this->assertFalse($this->actingAs($staff)->get(PreciosSede::getUrl())->isOk(), 'a counter-only login is sent back to the counter');
        $this->assertFalse(PreciosSede::canAccess());
        Livewire::test(PreciosSede::class)->assertForbidden();
    }
}
