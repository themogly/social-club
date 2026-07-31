<?php

namespace Tests\Feature\Settings;

use App\Actions\Wallet\AutoSettleDebt;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 59 — one per-location settings store. The LocationForm toggles now write location-scoped
 * Setting rows (the store Settings::get reads), the locations.settings JSON column is retired, and a
 * generic guard proves no toggle is dead. Crucially, the admin-form path is tested end-to-end — the
 * gap the prompt-44 test masked by writing a Setting row directly.
 */
class PerLocationStorageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_a_location_scoped_override_overrides_the_org_value_only_there(): void
    {
        Settings::set('signature_on_dispensation', false, SettingType::BOOL);                  // org: off
        Settings::set('signature_on_dispensation', true, SettingType::BOOL, $this->a->id);     // A: on

        $this->assertTrue((bool) Settings::get('signature_on_dispensation', false, $this->a->id));  // A overridden
        $this->assertFalse((bool) Settings::get('signature_on_dispensation', false, $this->b->id)); // B = org value
    }

    public function test_no_override_falls_back_to_the_org_value_not_the_code_default(): void
    {
        // Org row says false; the code DEFAULT is false too, so make them differ to prove precedence:
        Settings::set('bar_enabled', false, SettingType::BOOL); // org override, no location row

        // The location has no override → it must read the ORG value (false), never DEFAULTS (true).
        $this->assertFalse((bool) Settings::get('bar_enabled', true, $this->a->id));
    }

    public function test_toggling_through_the_location_edit_form_changes_the_pos_end_to_end(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->a->id]);
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($this->a->id);
        CounterOperator::set($owner);

        // Baseline: the POS does NOT require a signature.
        Livewire::test(DispensaryPos::class)->assertViewHas('requireSignature', false);

        // Turn the toggle ON through the ADMIN form an admin actually uses (not a direct Setting write).
        Livewire::test(EditLocation::class, ['record' => $this->a->id])
            ->fillForm(['signature_on_dispensation' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        // The counter now enforces it — the admin control genuinely drives behaviour.
        Livewire::test(DispensaryPos::class)->assertViewHas('requireSignature', true);
    }

    public function test_ring_fenced_data_survives_as_a_location_setting_row(): void
    {
        // The migration moves ring_fenced JSON into a Setting row; once there, isRingFenced reads it.
        Settings::set('ring_fenced', true, SettingType::BOOL, $this->a->id);

        $this->assertTrue(AutoSettleDebt::isRingFenced($this->a));
        $this->assertFalse(AutoSettleDebt::isRingFenced($this->b));
    }

    public function test_the_retired_json_store_is_gone(): void
    {
        // No column, no cast — the second mechanism cannot be written or read any more.
        $this->assertFalse(Schema::hasColumn('locations', 'settings'));
        $this->assertArrayNotHasKey('settings', (new Location)->getCasts());
    }

    public function test_every_location_toggle_has_a_real_reader(): void
    {
        // Generic dead-key guard (replaces any enumerated one): every settings.* toggle on the
        // LocationForm must be read by real code somewhere — so the next dead toggle fails here.
        $sources = collect(File::allFiles(app_path()))
            ->merge(File::allFiles(resource_path('views')))
            ->filter(fn ($file): bool => in_array($file->getExtension(), ['php'], true))
            ->map(fn ($file): string => (string) File::get($file->getPathname()))
            ->implode("\n");

        foreach (LocationForm::SETTING_TOGGLES as $key) {
            $this->assertTrue(
                str_contains($sources, "Settings::get('{$key}'"),
                "The per-location toggle '{$key}' is written by the LocationForm but read by nothing — a dead toggle.",
            );
        }
    }
}
