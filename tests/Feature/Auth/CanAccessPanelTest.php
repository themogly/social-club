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
        $user->assignRole(Role::STAFF->value);

        $this->assertTrue($this->gate($user));
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
