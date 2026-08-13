<?php

namespace Tests\Feature\Socio;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Prompt 232 — a page with Alpine directives must ship Alpine.
 *
 * The signature pad is an Alpine component, and Alpine only ever arrived on a page inside LIVEWIRE's bundle.
 * The counter routes load Livewire, so the pad worked there; the applicant's own form is plain Blade under
 * `layouts/socio`, which loaded a stylesheet and nothing else. Measured live on `9478612`: `window.Alpine`
 * undefined, the canvas at its 300×150 DEFAULT attributes stretched by CSS to 668×335, a stroke leaving
 * **0 ink pixels**, and *Guardar firma* leaving the hidden field empty — so with `signature_on_application`
 * at its default the server refused every submit and **the emailed invite route could not be completed**.
 *
 * This is prompt 196's class one level up. 196 caught directives sitting OUTSIDE an `x-data` scope; these sat
 * correctly INSIDE one, so its guard passed. What nobody checked was that the runtime ships — and the failure
 * is silent either way, because Alpine does not warn about a page it was never loaded on.
 *
 * So the rule is measured: any socio-family view that introduces a directive must also load the entry that
 * starts Alpine. The counter's "Alpine ships inside Livewire" assumption is true there and is not this
 * guard's business.
 */
class AlpineShipsWhereItIsUsedTest extends TestCase
{
    /** The entry that starts Alpine for the member/applicant app. */
    private const ENTRY = 'resources/js/socio.js';

    /** Views rendered under `layouts.socio` — the pages Livewire never reaches. */
    private const ROOTS = ['views/socio', 'views/components/socio'];

    /** @return array<string, string> relative path => contents */
    private function socioViews(): array
    {
        $views = [];

        foreach (self::ROOTS as $root) {
            $dir = resource_path($root);
            if (! is_dir($dir)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                    $views[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $views;
    }

    /**
     * Does this view need Alpine — either in its own markup, or through a component it renders?
     *
     * The applicant form's directives are not in its own bytes: they arrive with
     * `<x-counter.signature-pad>`. A reader that only looked at the file would have missed the exact defect
     * this test exists for.
     *
     * @return list<string>
     */
    private function needsAlpine(string $blade): array
    {
        $sources = [$blade];

        preg_match_all('/<x-([a-z0-9.-]+)/i', $blade, $components);

        foreach (array_unique($components[1]) as $component) {
            $path = resource_path('views/components/'.str_replace('.', '/', $component).'.blade.php');

            if (is_file($path)) {
                $sources[] = (string) file_get_contents($path);
            }
        }

        $reasons = [];

        foreach ($sources as $i => $source) {
            // Blade comments stripped: these files EXPLAIN the directives they carry, and prose is not markup
            // (215, 222, 228 and 230 each learned this the same way).
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            if (preg_match('/\sx-data[=\s>]/', $markup)) {
                $reasons[] = $i === 0 ? 'x-data in the view' : 'x-data in a component it renders';
            }
        }

        return array_values(array_unique($reasons));
    }

    // --- The guard ------------------------------------------------------------------------------

    /**
     * **Every socio view that uses Alpine loads the entry that starts it.**
     *
     * Fails against `9478612` naming `socio/application.blade.php`.
     */
    public function test_every_socio_view_with_directives_ships_alpine(): void
    {
        $this->assertFileExists(base_path(self::ENTRY), 'the socio Alpine entry is gone');

        $offenders = [];

        foreach ($this->socioViews() as $path => $blade) {
            $reasons = $this->needsAlpine($blade);

            if ($reasons === []) {
                continue;
            }

            $loadsIt = str_contains($blade, self::ENTRY)
                || str_contains((string) file_get_contents(resource_path('views/components/layouts/socio.blade.php')), self::ENTRY);

            if (! $loadsIt) {
                $offenders[] = $path.' — '.implode(', ', $reasons);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These socio views use Alpine and never load it:',
            '  '.implode("\n  ", $offenders),
            '',
            'Alpine reaches a page only through Livewire\'s bundle or through this entry, and the socio layout',
            'loads no Livewire. Without it every x-data, @click and x-ref on the page is dead markup — silently,',
            'because Alpine does not warn about a page it was never loaded on. Prompt 232: the applicant could',
            'not sign, so the emailed invite route could not be completed at all.',
            '',
            "    @vite('".self::ENTRY."')",
        ]));
    }

    /** The guard sees a directive that arrives through a COMPONENT, which is how the real one was missed. */
    public function test_the_guard_sees_directives_that_come_from_a_component(): void
    {
        $throughComponent = '<div><x-counter.signature-pad mode="form" /></div>';
        $inTheViewItself = '<div x-data="{ open: false }"></div>';
        $prose = '{{-- this view once had an x-data and no longer does --}}<p>hello</p>';

        $this->assertNotEmpty($this->needsAlpine($throughComponent), 'a component\'s directives are invisible to the guard');
        $this->assertNotEmpty($this->needsAlpine($inTheViewItself));
        $this->assertSame([], $this->needsAlpine($prose), 'the guard reads its own comments as markup');
    }

    /** The entry starts Alpine, and is declared to the build. */
    public function test_the_entry_starts_alpine_and_is_built(): void
    {
        $entry = (string) file_get_contents(base_path(self::ENTRY));

        $this->assertStringContainsString("import Alpine from 'alpinejs'", $entry);
        $this->assertStringContainsString('Alpine.start()', $entry, 'the entry imports Alpine and never starts it');

        $this->assertStringContainsString(self::ENTRY, (string) file_get_contents(base_path('vite.config.js')), 'the entry is not a Vite input');

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $this->assertArrayHasKey('alpinejs', $package['dependencies'] ?? [], 'alpinejs is not an explicit dependency');
    }

    /** The counter keeps Livewire's Alpine: this entry must never load where Livewire runs. */
    public function test_the_counter_does_not_load_a_second_alpine(): void
    {
        $counter = (string) file_get_contents(resource_path('views/components/layouts/counter.blade.php'));

        $this->assertStringNotContainsString(self::ENTRY, $counter, 'the counter would start a second Alpine beside Livewire\'s');

        foreach (glob(resource_path('views/livewire/**/*.blade.php')) ?: [] as $path) {
            $this->assertStringNotContainsString(self::ENTRY, (string) file_get_contents($path), basename($path).' loads a second Alpine');
        }
    }
}
