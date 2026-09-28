<?php

namespace Tests\Feature\Locations;

use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 283 — the owner: *"If it's storage it doesn't need operating hours."* 277 hid the counter-only sections for an
 * Almacén / cultivo but left Horario's opening/closing times and the accent colour (counter theming), and titled the
 * first section "Datos de la sede" on a location that is not a sede. The business-day cutoff stays: it decides which
 * day a stock movement at the store belongs to.
 */
class AlmacenHasNoOpeningHoursTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);
    }

    public function test_creating_an_almacen_hides_hours_and_accent_and_a_sede_still_shows_them(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['kind' => LocationKind::ALMACEN->value])
            ->assertFormFieldHidden('opening_time')
            ->assertFormFieldHidden('closing_time')
            ->assertFormFieldHidden('accent')
            ->assertFormFieldVisible('business_day_cutoff')
            ->assertFormFieldVisible('timezone');

        Livewire::test(CreateLocation::class)
            ->fillForm(['kind' => LocationKind::SEDE->value])
            ->assertFormFieldVisible('opening_time')
            ->assertFormFieldVisible('closing_time')
            ->assertFormFieldVisible('accent');
    }

    public function test_an_almacen_saves_null_hours_and_accent_even_when_filled_before_switching(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Cultivo', 'opening_time' => '10:00', 'closing_time' => '20:00', 'accent' => '#16a34a'])
            ->fillForm(['kind' => LocationKind::ALMACEN->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $store = Location::query()->where('name', 'Cultivo')->sole();
        $this->assertSame(LocationKind::ALMACEN, $store->kind);
        $this->assertNull($store->opening_time);
        $this->assertNull($store->closing_time);
        $this->assertNull($store->accent);
    }

    public function test_an_almacen_still_requires_the_cutoff_and_defaults_it_to_06_00(): void
    {
        // The cutoff's helper says what it means at a store (stock movements), not at a counter.
        Livewire::test(CreateLocation::class)
            ->fillForm(['kind' => LocationKind::ALMACEN->value])
            ->assertSee(__('Decide a qué día se asignan los movimientos de stock del almacén. Normalmente 06:00.'));

        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Sin corte', 'kind' => LocationKind::ALMACEN->value, 'business_day_cutoff' => null])
            ->call('create')
            ->assertHasFormErrors(['business_day_cutoff' => 'required']);

        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Cultivo', 'kind' => LocationKind::ALMACEN->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $store = Location::query()->where('name', 'Cultivo')->sole();
        $this->assertSame('06:00', CarbonImmutable::parse($store->business_day_cutoff)->format('H:i'));
    }

    public function test_editing_an_almacen_hides_the_three_fields_and_saving_clears_them(): void
    {
        // A store row that carries leftover values (as one made before this prompt could).
        $store = Location::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN, 'capacity' => null,
            'opening_time' => '09:00', 'closing_time' => '22:00', 'accent' => '#16a34a',
        ]);

        Livewire::test(EditLocation::class, ['record' => $store->getKey()])
            ->assertFormFieldHidden('opening_time')
            ->assertFormFieldHidden('closing_time')
            ->assertFormFieldHidden('accent')
            ->assertFormFieldVisible('business_day_cutoff')
            ->fillForm(['name' => 'Cultivo renombrado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $store->refresh();
        $this->assertSame('Cultivo renombrado', $store->name);
        $this->assertNull($store->opening_time);
        $this->assertNull($store->closing_time);
        $this->assertNull($store->accent);
    }

    public function test_editing_a_sede_keeps_its_hours_and_accent(): void
    {
        $sede = Location::factory()->create([
            'organisation_id' => $this->org->id, 'opening_time' => '09:00', 'closing_time' => '22:00', 'accent' => '#16a34a',
        ]);

        Livewire::test(EditLocation::class, ['record' => $sede->getKey()])
            ->assertFormFieldVisible('opening_time')
            ->call('save')
            ->assertHasNoFormErrors();

        $sede->refresh();
        $this->assertSame('09:00', CarbonImmutable::parse($sede->opening_time)->format('H:i'));
        $this->assertSame('#16a34a', $sede->accent);
    }

    public function test_the_first_section_is_titled_for_what_the_location_is(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['kind' => LocationKind::ALMACEN->value])
            ->assertSee(__('Datos de la ubicación'))
            ->assertDontSee(__('Datos de la sede'));

        Livewire::test(CreateLocation::class)
            ->fillForm(['kind' => LocationKind::SEDE->value])
            ->assertSee(__('Datos de la sede'))
            ->assertDontSee(__('Datos de la ubicación'));
    }

    public function test_the_list_does_not_warn_a_store_that_it_has_no_prices(): void
    {
        // Wider check: "Sin precios, esta sede no puede dispensar nada" is a counter warning; a store never dispenses.
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN, 'capacity' => null]);

        Livewire::test(ListLocations::class)
            ->assertTableColumnStateSet('prices_gap', null, $store);
    }
}
