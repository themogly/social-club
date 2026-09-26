<?php

namespace Tests\Feature\Design;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Prompt 252 — every screen a person can reach has a way back; no dead ends.
 *
 * A page with no link back is the same trap as a new tab (249's thank-you was the worst case). Every full-page
 * counter route carries the counter top bar (the way to the hub/panel) or, mid-handover, the surface; the member
 * shell carries its nav; the standalone receipt carries a `data-way-back` (asserted in DispensaryPosScreenTest).
 * The counter routes are enumerated FROM THE ROUTE TABLE, so a new counter screen with no chrome is caught.
 */
class EveryScreenHasAWayBackTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $user = User::factory()->create(['pin' => Hash::make('4321')]);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    /** @return list<string> Full-page counter GET routes (no params), from the route table. */
    private function counterScreenUris(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if ($uri !== 'counter' && ! str_starts_with($uri, 'counter/')) {
                continue;
            }
            if (str_contains($uri, '{')) {
                continue; // receipts and photo carry a param — the receipt way-back is pinned elsewhere
            }
            $controller = $route->getAction('controller');
            if (! is_string($controller) || str_contains($controller, '@')) {
                continue; // POST-style controllers (location switch, panic) are not full pages
            }
            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    public function test_every_counter_screen_carries_a_way_back(): void
    {
        $uris = $this->counterScreenUris();
        $this->assertGreaterThanOrEqual(6, count($uris), 'fewer counter screens enumerated than expected');

        foreach ($uris as $uri) {
            $html = (string) $this->followingRedirects()->get('/'.$uri)->assertOk()->getContent();

            $hasWayBack = str_contains($html, 'data-counter-topbar')
                || str_contains($html, 'data-counter-surface')
                || str_contains($html, 'data-way-back');

            $this->assertTrue($hasWayBack, "/$uri renders no way back (no top bar, surface, or data-way-back)");
        }
    }

    public function test_the_member_shell_carries_its_nav(): void
    {
        $org = Organisation::query()->firstOrFail();
        $member = Member::factory()->create(['organisation_id' => $org->id]);

        $html = (string) $this->actingAs($member, 'member')
            ->followingRedirects()->get(route('socio.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-socio-nav', $html, 'the member shell has no nav to move around by');
    }
}
