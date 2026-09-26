<?php

namespace Tests\Feature\Counter;

use Tests\TestCase;

/**
 * Prompt 245 — no `x-if` inside a Livewire-morphed view (`resources/views/livewire/**`).
 *
 * `x-if` makes Alpine INSERT its content as a sibling of the `<template>`. Livewire morphs the SERVER HTML,
 * which contains only the `<template>` — so the inserted clone is owned by neither: on a re-render that flips
 * the condition (a "Cambiar de persona" morph clears the operator while the surface's Alpine state changes at
 * the same moment), the old clone survives the morph and the re-initialised `x-if` inserts a SECOND. The
 * reported symptom was two PIN cards side by side on one surface.
 *
 * `x-show` is the fix: the element is always in the server HTML, present exactly once, and Alpine only toggles
 * its visibility — a morph has nothing to duplicate. `x-for` is untouched (it legitimately requires a
 * `<template>` and does not have this failure mode). This is 188's and 223's family from Alpine's side: 188
 * closed stale-state-in-a-morph, 223 closed scripts-in-morphed-views, this closes templates-in-morphed-views.
 */
class CounterViewsUseXShowNotXIfTest extends TestCase
{
    /** Every Blade view under resources/views/livewire — the views Livewire morphs. */
    private function livewireViewFiles(): array
    {
        $root = resource_path('views/livewire');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    /** Blade comments NAME the anti-pattern to explain the fix — they are prose, not directives. */
    private function withoutComments(string $blade): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    }

    /** The offending files, as a pure function of a {path => contents} map — so the plant can exercise it. */
    private function offenders(array $files): array
    {
        $bad = [];
        foreach ($files as $path => $contents) {
            if (preg_match('/\bx-if\b/', $this->withoutComments($contents))) {
                $bad[] = str_replace(resource_path('views/'), '', $path)
                    .': uses x-if inside a Livewire-morphed view — Alpine-inserted DOM in a morph target duplicates on re-render (prompt 245). Use x-show (+ x-cloak) on always-present markup instead.';
            }
        }

        return $bad;
    }

    public function test_no_livewire_view_uses_x_if(): void
    {
        $files = $this->livewireViewFiles();
        $this->assertGreaterThan(10, count($files), 'the livewire view set looks too small to be a real sweep');

        $map = [];
        foreach ($files as $f) {
            $map[$f] = (string) file_get_contents($f);
        }

        $this->assertSame([], $this->offenders($map), implode("\n", $this->offenders($map)));
    }

    /** The plant (prompt 245): the guard MUST catch a template x-if, or it guards nothing. */
    public function test_the_guard_catches_a_planted_x_if(): void
    {
        $planted = [
            resource_path('views/livewire/counter/_planted.blade.php') => '<template x-if="open"><div>boom</div></template>',
        ];

        $offenders = $this->offenders($planted);

        $this->assertNotEmpty($offenders, 'the guard did not catch a planted x-if');
        $this->assertStringContainsString('x-show', $offenders[0], 'the failure message must name the fix');
    }

    /** …and a Blade comment that MENTIONS x-if (like the ones explaining this fix) is not a false positive. */
    public function test_a_comment_mentioning_x_if_is_not_flagged(): void
    {
        $onlyComment = [
            resource_path('views/livewire/counter/_comment.blade.php') => "{{-- use x-show, never x-if, here --}}\n<div x-show=\"open\"></div>",
        ];

        $this->assertSame([], $this->offenders($onlyComment));
    }
}
