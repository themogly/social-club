<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_panel_root_to_login(): void
    {
        $this->get('/')->assertRedirectContains('login');
    }

    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_verified_staff_user_reaches_the_dashboard(): void
    {
        $user = User::factory()->create(); // factory sets email_verified_at

        $this->actingAs($user)->get('/')->assertOk();
    }

    /** Denial test: the panel gate blocks an unverified staff account. */
    public function test_unverified_user_cannot_access_the_panel(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/')->assertForbidden();
    }
}
