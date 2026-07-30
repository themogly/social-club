<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_is_redirected_from_the_panel_root_to_login(): void
    {
        $this->get('/')->assertRedirectContains('login');
    }

    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_active_staff_user_with_a_role_reaches_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);

        $this->actingAs($user)->get('/')->assertOk();
    }

    /** Denial: the panel gate blocks an account with no role. */
    public function test_user_without_a_role_cannot_access_the_panel(): void
    {
        $user = User::factory()->create(); // no role assigned

        $this->actingAs($user)->get('/')->assertForbidden();
    }
}
