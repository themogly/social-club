<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Prompt 151 — the panel theme must @source-scan every Blade rendered INSIDE the panel, or that view's
 * hand-written Tailwind utilities compile only into app.css (which the panel never loads) and silently do
 * nothing in the browser. This has now happened three times (help pages; the two topbar switchers). These are
 * the guards that stop a fourth: one at the SOURCE level (no build needed — catches a missing @source), one on
 * the BUILT theme (proves the utilities actually reached the stylesheet the panel serves).
 */
class PanelThemeSourceTest extends TestCase
{
    private const THEME_CSS = 'resources/css/filament/admin/theme.css';

    /**
     * Every view rendered inside the panel by a render hook in AdminPanelProvider. `filament.*` views are
     * already covered by the two long-standing globs; the two switchers are the paths prompt 151 added.
     *
     * @var list<string>
     */
    private const PANEL_RENDERED_VIEWS = [
        'resources/views/livewire/location-switcher.blade.php', // TOPBAR_START → LocationSwitcher
        'resources/views/livewire/locale-switcher.blade.php',   // GLOBAL_SEARCH_AFTER → LocaleSwitcher
        'resources/views/filament/help-menu.blade.php',         // GLOBAL_SEARCH_AFTER → @include('filament.help-menu')
    ];

    public function test_every_panel_rendered_view_resolves_inside_a_scanned_source_directory(): void
    {
        [$includes, $excludes] = $this->themeSourceDirs();

        foreach (self::PANEL_RENDERED_VIEWS as $view) {
            $covered = $this->matchedBy($view, $includes);
            $excluded = $this->matchedBy($view, $excludes);

            $this->assertTrue(
                $covered && ! $excluded,
                "{$view} is rendered inside the panel but is NOT scanned by ".self::THEME_CSS.
                ' — its utilities would compile only into app.css. Add its directory to the @source list.'
            );
        }
    }

    public function test_the_counter_views_are_deliberately_excluded_from_the_panel_theme(): void
    {
        // Deliberate scope (prompt 151): counter/ runs on its OWN layout served by app.css and never renders in
        // the panel, so its large utility set must not bloat the theme loaded on every admin page.
        [, $excludes] = $this->themeSourceDirs();

        $this->assertTrue(
            $this->matchedBy('resources/views/livewire/counter/dispensary-pos.blade.php', $excludes),
            'resources/views/livewire/counter/ must be excluded from the panel theme scan (it is app.css-served).'
        );
    }

    public function test_the_built_panel_theme_contains_the_topbar_utilities(): void
    {
        $themes = glob(public_path('build/assets/theme-*.css')) ?: [];
        if ($themes === []) {
            $this->markTestSkipped('Panel theme not built — run `npm run build` (CI does this before the suite).');
        }
        $css = (string) file_get_contents($themes[0]);

        // Arbitrary/hand-written utilities from the scanned livewire views. Filament's own theme does NOT emit
        // these, so their presence proves the @source scan reached the switchers (not a coincidental overlap).
        foreach ([
            'min-h-\[1\.5rem\]',            // the 24px min-height floor (prompt 98) on the locale segments
            'min-w-\[1\.75rem\]',           // the 28px min-width floor
            'bg-\[var\(--primary-600\)\]',  // the active-segment fill (prompt 143's actual fix)
            'pl-3',                          // the location switcher's horizontal padding
        ] as $utility) {
            $this->assertStringContainsString(
                $utility,
                $css,
                "The built panel theme is missing `{$utility}` — a topbar utility compiled into app.css but not the theme."
            );
        }
    }

    /**
     * Resolve the theme's `@source` / `@source not` globs to project-relative base directories.
     *
     * @return array{0: list<string>, 1: list<string>} [includes, excludes]
     */
    private function themeSourceDirs(): array
    {
        $themeDir = dirname(base_path(self::THEME_CSS));
        $css = (string) file_get_contents(base_path(self::THEME_CSS));

        $includes = [];
        $excludes = [];
        foreach (preg_split('/\R/', $css) ?: [] as $line) {
            if (preg_match("/@source\s+not\s+'([^']+)'/", $line, $m)) {
                $excludes[] = $this->resolveBase($themeDir, $m[1]);
            } elseif (preg_match("/@source\s+'([^']+)'/", $line, $m)) {
                $includes[] = $this->resolveBase($themeDir, $m[1]);
            }
        }

        return [array_values(array_filter($includes)), array_values(array_filter($excludes))];
    }

    /** A recursive `<dir>/**` glob → the project-relative `<dir>` it scans. */
    private function resolveBase(string $themeDir, string $glob): ?string
    {
        $base = (string) preg_replace('#/\*\*.*$#', '', $glob);
        $abs = realpath($themeDir.'/'.$base);

        return $abs !== false ? str_replace(base_path().DIRECTORY_SEPARATOR, '', $abs) : null;
    }

    /** @param list<string> $dirs */
    private function matchedBy(string $view, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if ($view === $dir || str_starts_with($view, $dir.'/')) {
                return true;
            }
        }

        return false;
    }
}
