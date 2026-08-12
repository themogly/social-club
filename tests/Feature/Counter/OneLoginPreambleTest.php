<?php

namespace Tests\Feature\Counter;

use Tests\TestCase;

/**
 * Prompt 226 — the sign-in preamble exists once, and stays once.
 *
 * Prompt 223 extracted `tests/Browser/counter-session.mjs` with its own header saying *"Three copies of a
 * login flow is how one of them quietly stops matching the app."* Then it wired ONE consumer of ten, and the
 * file it was extracted from kept its inline copy for four prompts.
 *
 * That is this project's most-repeated defect, and its FIFTH instance: `OpensMemberships` (203 wired Socios,
 * 211 the rest), the MRZ partial (179 the public form, 215 the staff one), the application field list (210
 * two copies, 215 one declaration), the signature canvas (113 the POS, 220 the component) — and this. Every
 * one shipped green, because a green suite proves a unit WORKS, never that everything which should use it
 * DOES.
 *
 * So the rule is measured rather than remembered: the seed credentials live in exactly one browser file. A
 * harness that hard-codes a login has, by definition, stopped sharing the flow — and it is the copy nobody
 * updates when the login form changes.
 */
class OneLoginPreambleTest extends TestCase
{
    /** The one file allowed to name them. */
    private const HOME = 'tests/Browser/counter-session.mjs';

    /** What a hard-coded login looks like, whoever wrote it. */
    private const CREDENTIALS = ['owner@club.test', 'AUDIT_EMAIL', 'DEV_EMAIL', 'AUDIT_PASSWORD', 'DEV_PASSWORD'];

    /** @return array<string, string> relative path => contents */
    private function harnesses(): array
    {
        $files = [];

        foreach (glob(base_path('tests/Browser/*.mjs')) ?: [] as $path) {
            $files[str_replace(base_path().'/', '', $path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function offenders(array $files): array
    {
        $found = [];

        foreach ($files as $path => $contents) {
            if ($path === self::HOME) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                // A line that IMPORTS from the helper mentions nothing; a line that assigns a credential does.
                if (str_contains($line, 'counter-session.mjs')) {
                    continue;
                }

                foreach (self::CREDENTIALS as $needle) {
                    if (str_contains($line, $needle)) {
                        $found[] = $path.':'.($index + 1).' — '.trim($line);
                        break;
                    }
                }
            }
        }

        return $found;
    }

    // --- The guard ------------------------------------------------------------------------------

    /**
     * **The dev-seed credentials appear in exactly one browser file.**
     *
     * Fails against `2306824`, naming nine harnesses.
     */
    public function test_only_one_browser_file_knows_how_to_log_in(): void
    {
        $files = $this->harnesses();

        $this->assertArrayHasKey(self::HOME, $files, 'the shared preamble is gone');
        $this->assertGreaterThan(5, count($files), 'no browser harnesses were read — the glob is stale');

        $offenders = $this->offenders($files);

        $this->assertSame([], $offenders, implode("\n", [
            'These browser harnesses hard-code a login instead of importing counter-session.mjs:',
            '  '.implode("\n  ", $offenders),
            '',
            'Prompt 223 extracted the preamble so it would exist once, and 226 finished the wiring. A copy is',
            'the one that quietly stops matching the app the next time the login form or the counter chain',
            'changes — and it will be the copy nobody runs that week.',
            '',
            "    import { signInToCounter } from './counter-session.mjs';",
        ]));
    }

    /** The guard catches one — planted, not assumed. */
    public function test_the_guard_catches_a_planted_login(): void
    {
        $planted = ['tests/Browser/planted.mjs' => "const page = 1;\nconst EMAIL = 'owner@club.test';\n"];

        $offenders = $this->offenders($planted);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('planted.mjs:2', $offenders[0], 'the failure does not name the line');

        // …while an IMPORT of the helper is not an offence, whatever it names.
        $this->assertSame([], $this->offenders([
            'tests/Browser/ok.mjs' => "import { EMAIL, signInToCounter } from './counter-session.mjs';\n",
        ]));
    }

    /** Every harness that drives the counter goes through the helper — not merely "does not hard-code one". */
    public function test_every_counter_harness_imports_the_shared_preamble(): void
    {
        $missing = [];

        foreach ($this->harnesses() as $path => $contents) {
            if ($path === self::HOME) {
                continue;
            }

            // A consumer is a harness that talks to the SERVER — not one that merely names a counter
            // selector. `shoot-surface-chain.mjs` loads static `file://` pages and queries the PIN pad's
            // hook to assert it is there; it has no session to sign in to, and a looser rule caught it as an
            // offender. The rule is "does it navigate to the running app", which is exactly who needs a
            // login flow to stay in step.
            $drivesTheApp = str_contains($contents, 'BASE_URL')
                || str_contains($contents, '127.0.0.1:8123');

            if ($drivesTheApp && ! str_contains($contents, "from './counter-session.mjs'")) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'these harnesses drive the real app without the shared preamble: '.implode(', ', $missing));
    }
}
