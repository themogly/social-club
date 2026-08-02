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
use Tests\TestCase;

/**
 * Prompt 132 — renders the REAL, authed counter top-bar (all five destinations + the overflow control) and
 * writes it, with the built CSS inlined, to storage/app/topbar-harness.html for the Playwright bounding-box
 * check (`node tests/Browser/measure-topbar.mjs`). It also doubles as a CI structural smoke test: the bar is one
 * flow whose secondary actions (Help/Panel/Log out) are collapsed behind a single 44px overflow control, so the
 * widened five-destination row cannot run into a wide fixed secondary group at any width. The pixel proof is the
 * Playwright script (Playwright is not in CI — see the README); this guards the STRUCTURE that makes overlap
 * impossible by construction.
 */
class TopbarHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_topbar_is_one_flow_with_secondary_actions_behind_one_overflow_control(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $loc = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);

        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value); // full access → all five destinations + Panel in the overflow
        $user->locations()->sync([$loc->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($loc->id);
        CounterOperator::set($user);

        $html = $this->get(route('counter.checkin'))->getContent();

        // All five counter destinations are present and reachable directly (not collapsed).
        foreach (['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'] as $route) {
            $this->assertStringContainsString('data-counter-screen="'.$route.'"', $html);
        }

        // The three non-destination actions are behind ONE overflow control — not loose in the bar.
        $this->assertStringContainsString('data-counter-overflow-trigger', $html);
        $this->assertStringContainsString('data-counter-help', $html);        // help folded into the overflow
        $this->assertStringContainsString('data-counter-dashboard', $html);   // Panel, inside the overflow
        $this->assertStringContainsString('data-counter-logout', $html);      // Log out, inside the overflow

        // Uniform, breakpoint-gated labelling (never a mixture); nav items and the overflow trigger are 44px.
        $this->assertStringContainsString('hidden lg:inline', $html);
        $this->assertStringNotContainsString('hidden md:inline', $html);
        $this->assertStringContainsString('h-11 w-11', $html);                 // 44px overflow trigger

        // Write the harness (with built CSS inlined) for the Playwright measurement, when assets are built.
        $css = '';
        foreach (glob(public_path('build/assets/*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }
        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }
        file_put_contents(storage_path('app/topbar-harness.html'), $html);
    }
}
