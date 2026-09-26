<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanAccessPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function gate(User $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function test_active_user_with_a_role_can_access(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole(Role::MANAGER->value); // managers hold panel.access by default (prompt 262)

        $this->assertTrue($this->gate($user));
    }

    public function test_a_counter_only_role_signs_in_but_is_not_given_the_panel(): void
    {
        // Prompt 262 — STAFF hold no panel.access by default: they may use the app (the counter) but not the panel.
        $user = User::factory()->create(['active' => true]);
        $user->assignRole(Role::STAFF->value);

        $this->assertTrue($user->canUseTheApp());
        $this->assertFalse($this->gate($user));
    }

    public function test_user_with_no_role_is_refused(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->assertFalse($this->gate($user));
    }

    public function test_deactivated_user_is_refused(): void
    {
        $user = User::factory()->create(['active' => false]);
        $user->assignRole(Role::OWNER->value);

        $this->assertFalse($this->gate($user));
    }
}
