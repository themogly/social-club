<?php

namespace Tests\Feature\Locations;

use App\Enums\Role;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 147 — the sede-create form must actually save. The three `time` columns are now TimePickers, so a
 * blank optional time dehydrates to NULL (not '', which a MySQL `time` column rejects with a 500) and the
 * cut-off arrives pre-filled and required. Run on MySQL: SQLite silently stores '' and would hide the bug.
 */
class LocationTimeFieldsTest extends TestCase
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

    public function test_creating_a_location_with_the_cutoff_untouched_stores_06_00(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Sede Uno'])   // cut-off keeps its 06:00 default
            ->call('create')
            ->assertHasNoFormErrors();

        $location = Location::query()->where('name', 'Sede Uno')->firstOrFail();
        $this->assertSame('06:00', CarbonImmutable::parse($location->business_day_cutoff)->format('H:i'));
    }

    public function test_blank_opening_and_closing_store_null_not_empty_string(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Sede Dos'])   // opening/closing left blank
            ->call('create')
            ->assertHasNoFormErrors();

        $location = Location::query()->where('name', 'Sede Dos')->firstOrFail();
        // The specific thing to verify, not assume: blank optional TimePickers dehydrate to NULL.
        $this->assertNull($location->opening_time);
        $this->assertNull($location->closing_time);
        // And at the raw DB level it is a real NULL, never '' (which would have 500'd on MySQL).
        $raw = DB::table('locations')->where('id', $location->id)->first();
        $this->assertNull($raw->opening_time);
        $this->assertNull($raw->closing_time);
    }

    public function test_an_emptied_cutoff_is_refused_with_a_validation_error_not_a_500(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Sede Tres', 'business_day_cutoff' => null])
            ->call('create')
            ->assertHasFormErrors(['business_day_cutoff']);

        $this->assertSame(0, Location::query()->where('name', 'Sede Tres')->count());
    }

    public function test_a_location_created_via_the_form_default_resolves_in_business_day(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['name' => 'Sede Cuatro'])
            ->call('create')
            ->assertHasNoFormErrors();

        $location = Location::query()->where('name', 'Sede Cuatro')->firstOrFail();
        $this->assertNotEmpty($location->business_day_cutoff);

        // BusinessDay resolves the operating-day window against the stored cut-off without error.
        [$start, $end] = BusinessDay::window($location, CarbonImmutable::parse('2026-08-04 10:00:00'));
        $this->assertTrue($end->greaterThan($start));
    }

    public function test_editing_a_location_without_touching_the_times_preserves_them(): void
    {
        $location = Location::factory()->create([
            'organisation_id' => $this->org->id,
            'business_day_cutoff' => '05:00', 'opening_time' => '09:00', 'closing_time' => '23:00',
        ]);

        Livewire::test(EditLocation::class, ['record' => $location->id])
            ->fillForm(['name' => 'Renombrada'])
            ->call('save')
            ->assertHasNoFormErrors();

        $location->refresh();
        $this->assertSame('05:00', CarbonImmutable::parse($location->business_day_cutoff)->format('H:i'));
        $this->assertSame('09:00', CarbonImmutable::parse($location->opening_time)->format('H:i'));
        $this->assertSame('23:00', CarbonImmutable::parse($location->closing_time)->format('H:i'));
    }
}
