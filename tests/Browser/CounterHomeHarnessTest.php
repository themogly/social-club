<?php

namespace Tests\Browser;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prompt 189 — writes the REAL, authed counter home to storage/app/counter-home.html for the Playwright pass
 * (`node tests/Browser/shoot-counter-home.mjs`).
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check: a tile per
 * reachable destination, the terminal operations that came off the bar, and no tile to a screen the operator
 * cannot open.
 */
class CounterHomeHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_counter_home_for_the_screenshot_pass(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($sede->id);

        $user = User::factory()->create(['name' => 'Marta Ruiz', 'pin' => Hash::make('4321')]);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$sede->id, Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Norte'])->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $sede->id]);
        CounterOperator::set($user);

        $html = $this->get(route('counter.home'))->getContent();

        // ONLY app-*.css — the counter layout loads that and nothing else (prompt 176).
        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }
        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }
        file_put_contents(storage_path('app/counter-home.html'), $html);

        // A tile per destination an OWNER may open — all five — and the operations that left the bar.
        foreach (['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'] as $route) {
            $this->assertStringContainsString('data-counter-home-tile="'.$route.'"', $html);
        }
        $this->assertStringContainsString('data-counter-home-switch-operator', $html);
        $this->assertStringContainsString('data-counter-home-lock', $html);
        $this->assertStringContainsString('data-counter-home-sedes', $html);
        // And the lock button is gone from the bar it used to crowd.
        $this->assertStringNotContainsString('data-counter-lock-now', $html);
    }
}
