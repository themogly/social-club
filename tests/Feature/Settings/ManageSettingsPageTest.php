<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\ManageSettings;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_it_loads_existing_values_on_mount(): void
    {
        Settings::set('carencia_days', 22, SettingType::INT);
        Settings::set('daily_limit_cg', 400, SettingType::CG);

        Livewire::test(ManageSettings::class)
            ->assertOk()
            ->assertSet('data.carencia_days', 22)
            ->assertSet('data.daily_limit_g', 4.0); // 400 cg shown as grams
    }

    public function test_it_validates_before_persisting(): void
    {
        Livewire::test(ManageSettings::class)
            ->set('data.min_age', null)
            ->call('save')
            ->assertHasErrors('data.min_age');
    }

    public function test_it_persists_and_notifies(): void
    {
        Livewire::test(ManageSettings::class)
            ->set('data.carencia_days', 33)
            ->set('data.daily_limit_g', 4)          // grams → 400 cg
            ->set('data.wallet_debt_allowed', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertSame(33, Settings::get('carencia_days'));
        $this->assertSame(400, Settings::get('daily_limit_cg'));
        $this->assertTrue(Settings::get('wallet_debt_allowed'));
    }

    public function test_saving_writes_an_audit_entry(): void
    {
        Livewire::test(ManageSettings::class)
            ->set('data.carencia_days', 45)
            ->call('save');

        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_the_page_is_denied_to_non_owners(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        // Owner (from setUp) may; staff may not.
        $this->assertTrue(ManageSettings::canAccess());
        $this->actingAs($staff);
        $this->assertFalse(ManageSettings::canAccess());
    }
}
