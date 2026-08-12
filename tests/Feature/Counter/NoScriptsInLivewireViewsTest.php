<?php

namespace Tests\Feature\Counter;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Prompt 223 — a JavaScript module must not be delivered inside markup Livewire morphs.
 *
 * Prompt 215 put `@vite('resources/js/mrz-reader.js')` inside `alta-staff-form.blade.php` — a partial that
 * only enters the page through a Livewire update and is morphed in and out as the wizard steps. So the module
 * arrived **inside the very update that inserted the control it was supposed to mount**, and every hook it
 * registered was registered after the only event that would have fired it. The trigger stayed `hidden`, which
 * is the correct progressive-enhancement default and therefore looked like a browser that could not run the
 * reader rather than a bug. It shipped green, twice, through two prompts that touched the same file.
 *
 * The rule this asserts is the general one, not that instance: **scripts load with the page, not with a
 * fragment of it.** A Livewire-rendered view is a fragment by definition — Livewire owns its lifetime and
 * replaces it whenever the server says so, and a re-inserted `<script src>` with an unchanged URL does not
 * re-execute. So there is nowhere in `resources/views/livewire/**` where a script tag can be relied upon.
 *
 * The allowed homes are layouts and full-page views, which is where the applicant form's own `@vite` lives —
 * an ordinary GET, loaded once, mounted on `DOMContentLoaded`, and working since 179.
 */
class NoScriptsInLivewireViewsTest extends TestCase
{
    /** Where a Livewire component's own markup lives — every file here is morph-owned. */
    private const LIVEWIRE_VIEWS = 'views/livewire';

    /**
     * @return array<string, string> relative path => contents
     */
    private function livewireViews(): array
    {
        $root = resource_path(self::LIVEWIRE_VIEWS);
        $views = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $views;
    }

    /**
     * Find every line that ships a script, with its line number so the failure names the place.
     *
     * `@vite` and a `<script src>` are the same defect: both ask the browser to fetch and execute a module
     * from inside a fragment. An INLINE `<script>` without a src is a different thing — Livewire re-executes
     * those on morph by design, and the counter layout's Alpine store uses one — so it is not matched.
     *
     * @return list<string>
     */
    private function offenders(string $path, string $blade): array
    {
        $found = [];

        foreach (explode("\n", $blade) as $index => $line) {
            // A directive, not the word: this test's own prose mentions `@vite` and must not fail itself
            // (prompt 215's parity reader read its own example; prompt 222's pad guard matched a selector).
            if (preg_match('/@vite\s*\(/', $line) || preg_match('/<script[^>]+\bsrc=/', $line)) {
                $found[] = $path.':'.($index + 1).' — '.trim($line);
            }
        }

        return $found;
    }

    // --- The guard ------------------------------------------------------------------------------

    /**
     * **No Livewire-rendered view loads a script.**
     *
     * Fails against `ad871fe` on `alta-staff-form.blade.php:109`, which is the defect this branch fixes.
     */
    public function test_no_livewire_view_ships_a_script(): void
    {
        $offenders = [];

        foreach ($this->livewireViews() as $path => $blade) {
            $offenders = array_merge($offenders, $this->offenders($path, $blade));
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These Livewire-rendered views load a script:',
            '  '.implode("\n  ", $offenders),
            '',
            'Livewire owns this markup and replaces it whenever the server re-renders — so the module arrives',
            'INSIDE the update that inserted the element it was meant to enhance, and every hook it registers',
            'is registered too late. A re-inserted <script src> with an unchanged URL does not re-execute',
            'either, so it does not heal on the next morph.',
            '',
            'Load it from resources/js/app.js (the counter layout already loads that with the page), or @vite',
            'it in a layout or a full-page view — anywhere outside the morph.',
        ]));
    }

    /** The guard actually catches one — planted, not assumed. */
    public function test_the_guard_catches_a_planted_violation(): void
    {
        $planted = "<div>\n    {{-- a partial that enhances itself --}}\n    @vite('resources/js/mrz-reader.js')\n</div>\n";

        $offenders = $this->offenders('resources/views/livewire/planted.blade.php', $planted);

        $this->assertCount(1, $offenders, 'the guard does not see a @vite inside a Livewire view');
        $this->assertStringContainsString('planted.blade.php:3', $offenders[0], 'the failure does not name the line');

        // …and a script tag, which is the same defect written by hand.
        $this->assertCount(1, $this->offenders('x.blade.php', '<script src="/js/thing.js"></script>'));

        // …while an INLINE script is not matched: Livewire re-runs those on morph by design.
        $this->assertSame([], $this->offenders('x.blade.php', '<script>window.x = 1</script>'));
    }

    /** The reader still loads — from the page, where it can hear the events it needs. */
    public function test_the_mrz_reader_loads_with_the_counter_page(): void
    {
        $entry = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('mrz-reader', $entry, 'the counter entry no longer loads the MRZ reader');

        $layout = (string) file_get_contents(resource_path('views/components/layouts/counter.blade.php'));
        $this->assertStringContainsString('resources/js/app.js', $layout, 'the counter layout no longer loads its entry bundle');
    }

    /**
     * The applicant's own form keeps its `@vite` — it is a full-page GET, which is the allowed shape, and it
     * is the one place the reader has always worked.
     */
    public function test_the_applicant_form_still_loads_it_itself(): void
    {
        $blade = (string) file_get_contents(resource_path('views/socio/application.blade.php'));

        $this->assertStringContainsString('mrz-reader.js', $blade, 'the applicant form stopped loading the reader');
        $this->assertFileExists(resource_path('views/socio/application.blade.php'));

        // …and it is NOT a Livewire view, so the guard above does not apply to it.
        $this->assertArrayNotHasKey('resources/views/socio/application.blade.php', $this->livewireViews());
    }

    /**
     * The engine stays lazy: the module that loads with the page must not pull tesseract in with it.
     *
     * The browser harness measures that no OCR request is made until the trigger is pressed; this pins the
     * mechanism that makes it true, so a static import cannot creep in and quietly add a megabyte to every
     * counter screen.
     */
    public function test_the_ocr_engine_is_imported_dynamically(): void
    {
        $reader = (string) file_get_contents(resource_path('js/mrz-reader.js'));

        $this->assertMatchesRegularExpression('/await import\([\'"]tesseract\.js[\'"]\)/', $reader, 'tesseract is no longer lazily imported');
        $this->assertDoesNotMatchRegularExpression('/^import .*tesseract/m', $reader, 'tesseract is statically imported — it would load on every counter screen');
    }
}
