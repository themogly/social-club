<?php

namespace Tests\Feature\Design;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 252 — the product is an app in a browser: nothing opens a new tab or window.
 *
 * On an Android tablet a new tab is a HIDDEN tab — Chrome switches to it, the counter is gone from the screen,
 * and the only way back is a control the operator was never taught (and a kiosk may hide). So nothing in the
 * app opens one: no `target="_blank"`, no `window.open`, no `openUrlInNewTab`. Overlays sit on top of the work
 * as modals or sheets (the alta modal, the receipt sheet, the document viewer).
 *
 * NO ALLOWLIST — a legitimate exception is a design change to make (a same-tab link, a modal), recorded in
 * DECISIONS, not a name added to a list here. The planted-violation test proves a green run means "clean", not
 * "the grep does nothing".
 */
class NothingLeavesTheTabTest extends TestCase
{
    private const PATTERNS = ['target="_blank"', 'window.open(', 'openUrlInNewTab('];

    /** The directories a tab could be opened from: rendered views, front-end JS, and the admin panel. */
    private function scannedDirs(): array
    {
        return [base_path('resources/views'), base_path('resources/js'), base_path('app/Filament')];
    }

    /**
     * @param  list<string>  $extraDirs
     * @return list<string> "path:line  <line>" for each offending line found
     */
    private function offenders(array $extraDirs = []): array
    {
        $offenders = [];

        foreach ([...$this->scannedDirs(), ...$extraDirs] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'blade'], true)) {
                    continue;
                }

                $lines = file($file->getPathname()) ?: [];
                foreach ($lines as $number => $line) {
                    foreach (self::PATTERNS as $pattern) {
                        if (str_contains($line, $pattern)) {
                            $offenders[] = Str::after($file->getPathname(), base_path().'/').':'.($number + 1).'  '.trim($line);
                        }
                    }
                }
            }
        }

        return $offenders;
    }

    public function test_nothing_in_the_app_opens_a_new_tab(): void
    {
        $offenders = $this->offenders();

        $this->assertSame([], $offenders,
            "Something leaves the app's tab (use a modal/sheet or a same-tab link; record the choice in DECISIONS):\n"
            .implode("\n", $offenders));
    }

    public function test_the_grep_catches_a_planted_violation(): void
    {
        // A temporary offending view, in a temp dir the scan is pointed at, proving the check is not a no-op.
        $dir = storage_path('framework/testing/nothing-leaves-'.uniqid());
        mkdir($dir, 0777, true);
        $planted = $dir.'/planted.blade.php';
        file_put_contents($planted, '<a href="/x" target="_blank">planted</a>'."\n");

        try {
            $offenders = $this->offenders([$dir]);
            $this->assertNotEmpty($offenders, 'the grep missed a planted target="_blank"');
            $this->assertTrue(
                collect($offenders)->contains(fn (string $o): bool => str_contains($o, 'planted.blade.php')),
                'the planted violation was not named',
            );
        } finally {
            @unlink($planted);
            @rmdir($dir);
        }
    }
}
