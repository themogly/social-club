<?php

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Prompt 313 — Back on the counter, the parts a browser run cannot keep watch over on every commit:
 *  · the start-of-history guard exists and runs ONLY in the installed app (display-mode standalone/fullscreen) — a
 *    normal tab's Back is never trapped;
 *  · no counter view adds a `popstate` listener that outlives its overlay (`{ once: true }` stayed behind when the
 *    overlay closed by any other route, and fired later on an unrelated Back): @if dialogs use `historyDialog`;
 *  · no counter sheet navigates one long-lived iframe (each navigation adds a joint history entry, so Back stepped the
 *    frame instead of closing the sheet).
 * The behaviour itself is proven in a real browser by tests/Browser/prove-313-back-guard.mjs.
 */
class CounterBackHistoryTest extends TestCase
{
    public function test_the_root_guard_runs_only_in_the_installed_app(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString('const rootBackGuard', $js);
        $this->assertMatchesRegularExpression('/standalone\(\)\s*\{\s*return \[\'standalone\', \'fullscreen\'\]/', $js);
        $this->assertMatchesRegularExpression('/install\(\)\s*\{\s*if \(! this\.standalone\(\)/', $js, 'the guard must bail out outside the installed app');
    }

    /**
     * Prompt 339 — what 313 lacked. The listener is installed on EVERY load (313 returned early when a page loaded on its
     * own guard entry — a reload, a Back into an earlier page — so Back walked through); the stack is topped up on a tap
     * (Chrome's Back skips entries pushed without a gesture); a backward traversal to an earlier page is cancelled where the
     * Navigation API allows; and the layout's <head> guards the launch before app.js, with the same depth and state shape.
     * Proven in a browser by tests/Browser/prove-339-back-guard.mjs; on the pinned tablet only by hand.
     */
    public function test_the_guard_holds_on_every_load_on_a_tap_and_at_launch(): void
    {
        $js = File::get(resource_path('js/app.js'));
        $layout = File::get(resource_path('views/components/layouts/counter.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/history\.state\?\.cscGuard\) return;/', $js, '313\'s early return is back');
        $this->assertMatchesRegularExpression("/\['pointerdown', 'keydown'\]\.forEach\(\(type\) => window\.addEventListener\(type, \(\) => this\.topUp\(\)/", $js, 'no top-up on a tap');
        $this->assertStringContainsString("window.navigation?.addEventListener('navigate'", $js);
        $this->assertStringContainsString('event.preventDefault()', $js);

        preg_match('/DEPTH: (\d+)/', $js, $depth);
        $this->assertNotEmpty($depth);
        $head = strpos($layout, 'history.pushState({ cscGuard: depth }');
        $this->assertNotFalse($head, 'no launch guard in the counter layout\'s <head>');
        $this->assertLessThan(strpos($layout, '@vite('), $head, 'the launch guard must run before app.js');
        $this->assertStringContainsString('depth <= '.$depth[1].';', $layout, 'the head script and app.js disagree on the depth');
        $this->assertStringContainsString("['standalone', 'fullscreen']", $layout, 'the head script must not run in a browser tab');
    }

    public function test_no_counter_view_leaves_a_popstate_listener_behind(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();
            $this->assertDoesNotMatchRegularExpression("/addEventListener\\('popstate'[^)]*\\{\\s*once:\\s*true\\s*\\}/", $source,
                $file->getRelativePathname().': a { once: true } popstate listener outlives an overlay closed any other way — use historyDialog.');
        }
    }

    public function test_the_counter_sheets_create_a_fresh_frame_per_opening(): void
    {
        foreach (['receipt-sheet', 'document-sheet'] as $sheet) {
            $source = File::get(resource_path("views/components/counter/{$sheet}.blade.php"));
            $this->assertMatchesRegularExpression('/<template x-if="[^"]+">\s*<iframe/', $source, "{$sheet}: navigate a fresh frame, not a long-lived one");
        }
    }
}
