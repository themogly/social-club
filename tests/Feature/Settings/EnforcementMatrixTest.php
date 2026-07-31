<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Filament\Pages\ManageEnforcement;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 53 — the enforcement matrix editor. The highest-stakes setting (BLOCK/WARN/OVERRIDE per rule,
 * per door/counter) was tinker-only. This proves it edits + persists through Settings::enforcement, that
 * the locked cells (age, aforo) stay BLOCK even against a tampered submit, and that it's owner-only.
 */
class EnforcementMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    public function test_it_edits_and_persists_a_matrix_cell_and_audits(): void
    {
        $this->actingAs($this->owner());
        $this->assertSame('BLOCK', Settings::enforcement('counter', 'debt')); // default

        Livewire::test(ManageEnforcement::class)
            ->set('data.counter.debt', 'WARN')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('WARN', Settings::enforcement('counter', 'debt'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.enforcement.updated']);
    }

    public function test_age_and_aforo_are_locked_to_block_even_if_tampered(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageEnforcement::class)
            ->set('data.door.aforo', 'WARN')
            ->set('data.counter.age', 'OVERRIDE')
            ->set('data.door.age', 'WARN')
            ->call('save');

        $this->assertSame('BLOCK', Settings::enforcement('door', 'aforo'));
        $this->assertSame('BLOCK', Settings::enforcement('counter', 'age'));
        $this->assertSame('BLOCK', Settings::enforcement('door', 'age'));
    }

    public function test_the_editor_is_owner_only(): void
    {
        $this->actingAs($this->owner());
        $this->assertTrue(ManageEnforcement::canAccess());

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $this->actingAs($manager);
        $this->assertFalse(ManageEnforcement::canAccess()); // settings.manage is owner-only
    }
}
