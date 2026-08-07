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

    /**
     * **Updated by prompt 205, not deleted.** The five-destination row this was written for is gone — the hub
     * is the menu — but *"no two controls overlap, none under 44px, at four widths"* is as valuable on a short
     * row as on a long one, and a short row is exactly where somebody would stop checking. The structural
     * half asserts the new contents; `measure-topbar.mjs` still measures the pixels.
     */
    public function test_the_topbar_is_one_flow_of_terminal_controls(): void
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

        // The destinations are NOT here any more — they are the hub's tiles (prompt 205).
        foreach (['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'] as $route) {
            $this->assertStringNotContainsString('data-counter-screen="'.$route.'"', $html);
        }

        // What the row carries instead: the terminal facts, each loose and each in exactly one place.
        $this->assertStringContainsString('data-counter-home-link', $html);        // labelled, not a logo
        $this->assertStringContainsString('data-counter-sede-region', $html);
        $this->assertStringContainsString('data-operator-name-chip', $html);
        $this->assertStringContainsString('data-counter-lock', $html);
        $this->assertStringContainsString('data-counter-dashboard', $html);        // Panel
        $this->assertStringContainsString('data-counter-logout', $html);
        $this->assertStringContainsString('data-counter-panic', $html);            // discreet, icon-only

        // The overflow is gone with the strip that made it necessary.
        $this->assertStringNotContainsString('data-counter-overflow-trigger', $html);

        // Uniform, breakpoint-gated labelling (never a mixture); the icon-only controls are 44px.
        $this->assertStringContainsString('hidden lg:inline', $html);
        $this->assertStringNotContainsString('hidden md:inline', $html);
        $this->assertStringContainsString('h-11 w-11', $html);                     // 44px panic control

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
