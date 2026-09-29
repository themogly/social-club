<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

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
        // A club that lets staff into the panel (the pre-262 default) — so this still tests the PAGE's own gate.
        $this->giveStaffThePanel();

        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([Location::factory()->create()->id]); // post-296 audit: a non-owner works at a sede

        $this->actingAs($user)->get('/')->assertOk();
    }

    /** Post-296 audit — a non-owner with no sede at all has nothing to see: refused, never the whole organisation. */
    public function test_a_non_owner_with_no_sede_is_refused_rather_than_shown_everything(): void
    {
        $this->giveStaffThePanel();
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);

        $this->actingAs($user)->get('/')->assertRedirect(route('panel.no-location')); // 310 — to the page that says so
    }

    /** Denial: the panel gate blocks an account with no role. */
    public function test_user_without_a_role_cannot_access_the_panel(): void
    {
        $user = User::factory()->create(); // no role assigned

        $this->actingAs($user)->get('/')->assertForbidden();
    }
}
