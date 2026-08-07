<?php

namespace Tests\Feature\Counter;

use Tests\TestCase;

/**
 * Prompt 209 — the CLASS of bug, not the instance.
 *
 * **The rule: nothing the counter layout branches on may be changeable by a Livewire component within the
 * same page life.** A Livewire response replaces the component's markup and nothing else — the Blade layout
 * is rendered once, on the full page load, and never again. So a branch there is a *snapshot*: it freezes
 * whatever the server said at page load, and no later action can correct it.
 *
 * The instance this was written for: `@unless (CounterHandover::active())` wrapped the top bar, and
 * `unlockOperator()` ends a handover inside a Livewire action. Ending it restored the counter and left it
 * with no chrome — no sede, no lock, no way to another screen — and since 205 made the bar the only
 * navigation, that stranded the terminal until somebody reloaded. It is prompt 188's failure one level out:
 * 188 was Alpine snapshotting server state into `x-data`; this was the layout snapshotting it into the DOM.
 *
 * **Where the line is drawn, and why it is derivable.** A counter component changes state by writing to the
 * SESSION — that is what `CounterHandover`, `CounterOperator` and `CounterBasket` all are. Route-derived
 * facts (the screen's title), deploy-time facts (whether a build manifest exists) and per-sede config (the
 * idle-lock window, changed in the admin panel — a different page life) are fixed for the life of the page
 * and are fine. So the test asks each `App\Support` class the layout reads whether its OWN source writes to
 * the session, rather than carrying a list of banned names that the next helper would not be on.
 */
class LayoutBranchesOnFixedFactsTest extends TestCase
{
    private const LAYOUT = 'resources/views/components/layouts/counter.blade.php';

    /** How a class writes session state — any of these makes it mutable within a page life. */
    private const SESSION_WRITES = ['session([', 'session()->put', 'session()->forget', 'Session::put', 'Session::forget', 'Session::flush'];

    public function test_the_counter_layout_reads_no_session_backed_state(): void
    {
        $layout = (string) file_get_contents(base_path(self::LAYOUT));

        // Comments describe the rule and name the offender it was written for; they are not code.
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $layout);

        preg_match_all('/\\\\?App\\\\Support\\\\(\w+)::/', $code, $matches);
        $referenced = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($referenced, 'the layout referenced no support class at all — has this test gone stale?');

        $offenders = [];

        foreach ($referenced as $class) {
            $path = base_path('app/Support/'.$class.'.php');

            if (! is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach (self::SESSION_WRITES as $write) {
                if (str_contains($source, $write)) {
                    $offenders[] = $class;

                    break;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'The counter layout branches on session-backed state: '.implode(', ', $offenders).'.',
            '',
            'THE MECHANISM YOU HAVE BROKEN: a Livewire response replaces the COMPONENT\'s markup and nothing',
            'else. '.self::LAYOUT.' is rendered once, on the full page load. Anything you read there is',
            'frozen at that moment, so a Livewire action that changes it can never correct what the layout',
            'drew — the user sees the stale version until they navigate or reload.',
            '',
            'This shipped once already (prompt 209): the top bar was wrapped in',
            '`@unless (CounterHandover::active())`, so ending a handover restored the counter and not its',
            'chrome, stranding the terminal with no navigation at all.',
            '',
            'THE FIX: move the branch into a Livewire component — see App\Livewire\Counter\CounterChrome,',
            'which the layout renders unconditionally and which decides for itself.',
        ]));
    }

    /**
     * And the specific line cannot come back, whatever it is spelled as.
     *
     * The check above is derived and would catch a new helper; this one is the instance, kept because it is
     * the one somebody will re-add while "simplifying the layout".
     */
    public function test_the_layout_does_not_ask_whether_a_handover_is_active(): void
    {
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path(self::LAYOUT)));

        $this->assertStringNotContainsString('CounterHandover', $code, implode("\n", [
            'The counter layout is deciding the chrome on handover state again.',
            'A Livewire response never re-renders this file, so ending a handover would restore the counter',
            'and leave it with no top bar — no sede, no lock and no way to another screen (prompt 209).',
            'App\Livewire\Counter\CounterChrome owns that decision.',
        ]));
    }

    /** The component that took the decision over really does make it — the rule needs somewhere to have gone. */
    public function test_the_chrome_component_is_the_one_asking(): void
    {
        $chrome = (string) file_get_contents(base_path('app/Livewire/Counter/CounterChrome.php'));

        $this->assertStringContainsString('CounterHandover::active()', $chrome);
        $this->assertStringContainsString("#[On('counter-unlocked')]", $chrome, 'the chrome would never learn that a handover ended');
        $this->assertStringContainsString("#[On('counter-lock')]", $chrome, 'the chrome would never learn that one timed out');
    }
}
