<?php

namespace Tests\Feature\Schema;

use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UlidRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_model_binding_uses_ulids_and_a_sequential_integer_id_404s(): void
    {
        Route::middleware('web')->get('/__test/members/{member}', fn (Member $member) => $member->id);

        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $member = Member::factory()->create(['organisation_id' => $org->id]);

        // A real ULID resolves.
        $this->get("/__test/members/{$member->id}")->assertOk()->assertSee($member->id);

        // A guessable sequential integer does not exist — no IDOR surface (NOTES §B).
        $this->get('/__test/members/1')->assertNotFound();
        $this->get('/__test/members/123')->assertNotFound();
    }
}
