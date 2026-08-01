<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Prompt 100 — custom Filament panel views had NO stylesheet (the panel used Filament's stock precompiled
 * CSS, which serves only the classes Filament's own components need). Hand-written Tailwind in a custom page
 * — the help manual/glossary — got nothing, so it rendered as raw text. The fix is a real Filament theme
 * (viteTheme) whose @source scans the custom views, sharing ONE token source with the counter/PWA.
 *
 * These guards split in two: the SOURCE guards (always run) fail if the theme is unregistered or the tokens
 * are forked; the COMPILED guards (skip without a build) assert the specific classes/values that were missing
 * actually reach the built bundle — the measurement that found the bug.
 */
class FilamentThemeTest extends TestCase
{
    private const THEME_ENTRY = 'resources/css/filament/admin/theme.css';

    private const APP_ENTRY = 'resources/css/app.css';

    /** The built file for a Vite entry, or null when the app has not been built (public/build is gitignored). */
    private function builtCss(string $entry): ?string
    {
        $manifestPath = public_path('build/manifest.json');
        if (! is_file($manifestPath)) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! isset($manifest[$entry]['file'])) {
            return null;
        }

        return (string) file_get_contents(public_path('build/'.$manifest[$entry]['file']));
    }

    public function test_the_admin_panel_registers_the_vite_theme(): void
    {
        // Without this, every custom panel view added from now on renders unstyled (the whole bug).
        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringContainsString(
            "->viteTheme('resources/css/filament/admin/theme.css')",
            $provider,
            'AdminPanelProvider must register the custom Filament theme via ->viteTheme().'
        );
    }

    public function test_the_theme_is_a_vite_build_entry(): void
    {
        $vite = (string) file_get_contents(base_path('vite.config.js'));
        $this->assertStringContainsString(self::THEME_ENTRY, $vite, 'The theme must be a Vite input so it is compiled.');
    }

    public function test_the_semantic_tokens_are_one_shared_source_not_a_fork(): void
    {
        // Both surfaces import the SAME partial — so prompt 98's WCAG values (and any future token edit) reach
        // the panel and the counter alike. A forked copy is exactly what this asserts against.
        $app = (string) file_get_contents(base_path(self::APP_ENTRY));
        $theme = (string) file_get_contents(base_path(self::THEME_ENTRY));

        $this->assertStringContainsString("@import './tokens.css'", $app);
        $this->assertStringContainsString('tokens.css', $theme);

        // The tokens partition themselves live in ONE place only.
        $this->assertStringNotContainsString('--color-success:', $app, 'app.css must not redefine tokens — they live in tokens.css.');
        $this->assertStringContainsString('--color-success:', (string) file_get_contents(base_path('resources/css/tokens.css')));
    }

    public function test_the_built_theme_serves_the_help_pages_utilities(): void
    {
        $css = $this->builtCss(self::THEME_ENTRY);
        if ($css === null) {
            $this->markTestSkipped('No build present — run `npm run build`.');
        }

        // The exact classes the manual/glosario use that Filament's stock stylesheet never served.
        foreach (['primary-50', 'primary-700', 'rounded-xl', 'grid-cols-2', 'tabular-nums', 'scroll-mt-24'] as $class) {
            $this->assertStringContainsString($class, $css, "The panel theme is missing '{$class}' — the help views need it.");
        }
    }

    public function test_the_built_tokens_are_identical_across_panel_and_counter(): void
    {
        $theme = $this->builtCss(self::THEME_ENTRY);
        $app = $this->builtCss(self::APP_ENTRY);
        if ($theme === null || $app === null) {
            $this->markTestSkipped('No build present — run `npm run build`.');
        }

        // Prompt 98's per-scheme WCAG-AA values must appear in BOTH bundles (one source), never one only.
        foreach (['#166534', '#92400e', '#b91c1c', '#f87171'] as $hex) {
            $this->assertStringContainsString($hex, $app, "counter bundle is missing shared token {$hex}");
            $this->assertStringContainsString($hex, $theme, "panel theme is missing shared token {$hex}");
        }
    }

    public function test_the_counter_bundle_still_carries_its_tokens(): void
    {
        // Regression: moving the tokens to the shared partial must not strip them from the counter/PWA bundle.
        $app = $this->builtCss(self::APP_ENTRY);
        if ($app === null) {
            $this->markTestSkipped('No build present — run `npm run build`.');
        }

        foreach (['--color-brand', '--color-ink', '--color-surface'] as $token) {
            $this->assertStringContainsString($token, $app, "counter bundle lost {$token}");
        }
    }
}
