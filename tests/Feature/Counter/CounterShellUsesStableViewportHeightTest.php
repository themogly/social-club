<?php

namespace Tests\Feature\Counter;

use Tests\TestCase;

/**
 * Prompt 237 — the counter's pinning shells measure their height against the STABLE viewport, never `100vh`.
 *
 * `h-screen` / `min-h-screen` resolve to `100vh`, and on a mobile browser `100vh` is the LARGEST viewport —
 * the height with the URL bar hidden. A shell sized to it is taller than what is actually on screen while the
 * bar is showing, so the foot of a pinned counter — `Registrar aportación`, `Cobrar`, the check-in button —
 * sits behind the address bar until the operator scrolls. That is worst on the phones handed across the
 * counter, where those buttons matter most and the bar is always there.
 *
 * `svh` (small viewport height) is the stable floor: the height with the UA chrome SHOWN. A shell sized to it
 * is never taller than the visible area and never shifts as the bar hides. `lvh`/`vh` reintroduce the bug;
 * `dvh` is allowed (it resizes but never exceeds the visible area), though `svh` is what the shell uses.
 *
 * This could not be caught by LOOKING in a headless run: a headless browser has no URL bar, so `100vh` and
 * `100svh` render identically there. It is a structural guard on the class names instead — a planted
 * `h-screen` on any counter shell fails it.
 */
class CounterShellUsesStableViewportHeightTest extends TestCase
{
    /** The counter's shells and every view rendered inside them. */
    private function counterViewFiles(): array
    {
        $roots = [
            resource_path('views/components/layouts/counter.blade.php'),
            ...glob(resource_path('views/livewire/counter/*.blade.php')) ?: [],
            ...glob(resource_path('views/livewire/counter/partials/*.blade.php')) ?: [],
            ...glob(resource_path('views/components/counter/*.blade.php')) ?: [],
        ];

        return array_values(array_filter($roots, 'is_file'));
    }

    /** Blade comments are prose (they NAME the anti-pattern to explain the fix), never rendered classes. */
    private function withoutComments(string $blade): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    }

    public function test_no_counter_shell_sizes_itself_to_the_unstable_viewport(): void
    {
        // `h-screen` as a substring catches `min-h-screen` and `max-h-screen` too; `\d+vh` catches a raw
        // `100vh`; `lvh` and an arbitrary `[..vh]` catch the largest-viewport variants. `svh`/`dvh` pass.
        $forbidden = [
            'h-screen' => 'h-screen / min-h-screen / max-h-screen resolve to 100vh — use h-svh',
            'lvh' => 'lvh is the LARGEST viewport and clips behind the URL bar — use svh',
        ];

        $files = $this->counterViewFiles();
        $this->assertGreaterThan(3, count($files), 'the counter view set looks too small to be a real sweep');

        foreach ($files as $file) {
            $blade = $this->withoutComments((string) file_get_contents($file));
            $short = str_replace(resource_path('views/'), '', $file);

            foreach ($forbidden as $needle => $why) {
                $this->assertStringNotContainsString($needle, $blade, "{$short}: {$why}");
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\d+vh\b/',
                $blade,
                "{$short}: a raw viewport-height value (Nvh) sizes past the visible area — use svh"
            );
        }
    }

    /** And positively: the shell that pins the counter uses the stable unit on both its height rules. */
    public function test_the_counter_shell_uses_svh(): void
    {
        $shell = $this->withoutComments(
            (string) file_get_contents(resource_path('views/components/layouts/counter.blade.php'))
        );

        $this->assertStringContainsString('min-h-svh', $shell);
        $this->assertStringContainsString('md:h-svh', $shell);
    }
}
