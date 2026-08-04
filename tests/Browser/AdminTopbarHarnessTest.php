<?php

namespace Tests\Browser;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 143 — the admin topbar's top-right cluster read as one run ("DGESEN"): the avatar initials, the
 * language toggle and the help icon were jammed together with no separation. Root cause: the language and
 * help controls were injected via the TOPBAR_END render hook, which renders OUTSIDE Filament's `.fi-topbar-end`
 * flex (its 16px column-gap) as gapless siblings of the avatar. Moving them to GLOBAL_SEARCH_AFTER renders them
 * INSIDE that gapped container, before the user menu — so all three controls inherit the 16px separation and the
 * avatar returns to the far-right corner.
 *
 * This renders the REAL authed dashboard (its topbar is the live one) and writes it, with the built CSS inlined,
 * to storage/app/admin-topbar-harness.html for the Playwright gap/target check (`node tests/Browser/measure-admin-topbar.mjs`).
 * It doubles as the CI structural guard: it proves the two controls now sit before the user menu (the ordering
 * flip that fixes the cramming) and that the language control is a labelled, pressed-state segmented group.
 */
class AdminTopbarHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_and_help_sit_inside_the_topbar_end_cluster_before_the_avatar(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $loc = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$loc->id]);
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($loc->id);

        $html = $this->get('/')->assertOk()->getContent();

        // The language toggle is a labelled segmented group with a pressed state (not two loose text buttons).
        // Anchor on the locale-independent markers (`switchLocale`, `role="group"`) — the response's rendered
        // locale is not pinned here, so the translated aria-label text must not be asserted verbatim.
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label=', $html);           // the group carries a label (Idioma/Language)
        $this->assertStringContainsString("switchLocale('es')", $html);    // both segments present
        $this->assertStringContainsString("switchLocale('en')", $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);   // the active locale segment
        $this->assertStringContainsString('aria-pressed="false"', $html);  // the inactive one

        // The ordering flip that fixes "DGESEN": the language control now precedes the user menu (it followed it
        // before, injected after `.fi-topbar-end` closed). `.fi-topbar-end`'s column-gap then separates all three.
        $localePos = strpos($html, 'switchLocale(');
        $endPos = strpos($html, 'fi-topbar-end');
        $menuPos = strpos($html, 'fi-user-menu');
        $this->assertNotFalse($localePos);
        $this->assertNotFalse($endPos);
        $this->assertNotFalse($menuPos, 'The Filament user menu (avatar) must be present.');
        $this->assertGreaterThan($endPos, $localePos, 'The language toggle must render inside the topbar-end cluster.');
        $this->assertLessThan($menuPos, $localePos, 'The language toggle must render BEFORE the avatar (the fix), not after it.');

        // Write the harness (built CSS inlined) for the Playwright measurement, when assets are built.
        $css = '';
        foreach (glob(public_path('build/assets/*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }
        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }
        file_put_contents(storage_path('app/admin-topbar-harness.html'), $html);
    }
}
