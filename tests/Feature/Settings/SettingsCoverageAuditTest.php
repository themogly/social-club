<?php

namespace Tests\Feature\Settings;

use App\Actions\ResolveLocale;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 44 — the settings-coverage audit's remaining fixes: aforo_default now seeds a new
 * Location's capacity (was a dead setting), and default_locale / enabled_locales /
 * minute_quorum_fraction_bp are now editable on the org settings page AND still read by the
 * SAME code that already consumed them (ResolveLocale / CreateMinute), not a second copy.
 */
class SettingsCoverageAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_aforo_default_seeds_a_new_locations_capacity_field(): void
    {
        Settings::set('aforo_default', 75, SettingType::INT);

        Livewire::test(CreateLocation::class)
            ->assertOk()
            ->assertSet('data.capacity', 75);
    }

    public function test_locale_and_quorum_settings_persist_and_are_read_by_their_consumers(): void
    {
        Livewire::test(ManageSettings::class)
            ->set('data.default_locale', 'es')
            ->set('data.enabled_locales', ['es'])
            ->set('data.minute_quorum_fraction_pct', 60) // percentage at the edge
            ->call('save')
            ->assertHasNoErrors();

        // Persisted on the exact keys the existing consumers already read.
        $this->assertSame('es', Settings::get('default_locale'));
        $this->assertSame(['es'], Settings::get('enabled_locales'));
        $this->assertSame(6000, Settings::get('minute_quorum_fraction_bp')); // 60% → 6000 bp (CreateMinute reads this)

        // ResolveLocale (unchanged) now resolves to the saved default for a user with no per-user locale.
        $this->assertSame('es', (new ResolveLocale)->handle(null));
    }
}
