<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 272 — the PIN pad from a keyboard (a11y audit, Phase 1).
 *
 * `@keydown.window.enter="open && padVisible && submit()"` treated EVERY Enter as "submit". Enter is how a focused
 * <button> is activated, and keydown reaches window before the button's click fires — so Enter on the second digit
 * key submitted the one digit typed so far ("PIN no reconocido"), each key after the first costing an attempt
 * against the lockout throttle. Since 267 this PIN is how everyone signs in, so a keyboard-only operator locked
 * themselves out by working the pad the standard way. There is no browser here; the rendered Alpine contract is
 * asserted, and the harness `tests/Browser/shoot-pre-live-design-fixes.mjs` drives it for real.
 */
class PinPadKeyboardTest extends TestCase
{
    use RefreshDatabase;

    private function surfaceHtml(): string
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($location->id);

        $device = User::factory()->create();
        $device->assignRole(Role::STAFF->value);
        $device->locations()->attach($location->id);

        $html = Livewire::actingAs($device)->test(TillSession::class)->html();
        $start = strpos($html, 'data-counter-surface');
        $this->assertNotFalse($start, 'the surface did not render');

        return substr($html, $start, 9000);
    }

    public function test_enter_on_a_focused_pad_key_does_not_submit_a_partial_pin(): void
    {
        $surface = $this->surfaceHtml();

        // The unconditional window-level Enter binding is gone…
        $this->assertStringNotContainsString('@keydown.window.enter', $surface);
        // …replaced by ONE handler that lets the surface's own buttons activate themselves on Enter.
        $this->assertStringContainsString('@keydown.window="onKey($event)"', $surface);
        $this->assertStringContainsString("if (e.target?.closest?.('[data-counter-surface] button')) return", $surface);
    }

    public function test_a_physical_keyboard_types_digits_and_backspace_into_the_pad(): void
    {
        $surface = $this->surfaceHtml();

        $this->assertStringContainsString('if (/^[0-9]$/.test(e.key)) { e.preventDefault(); this.push(e.key); return }', $surface);
        $this->assertStringContainsString("if (e.key === 'Backspace') { e.preventDefault(); this.back() }", $surface);
        // Only while the pad is actually up — never swallowing keys from the counter behind a closed surface.
        $this->assertStringContainsString('if (! this.open || ! this.padVisible', $surface);
    }

    public function test_the_pad_speaks_to_a_screen_reader(): void
    {
        $surface = $this->surfaceHtml();

        // The dots are aria-hidden, so the COUNT is announced (never the digits).
        $this->assertMatchesRegularExpression('/data-pin-count class="sr-only" aria-live="polite"/', $surface);
        $this->assertStringContainsString(__('1 dígito introducido'), $surface);
    }

    public function test_focus_moves_into_the_surface_and_back_without_a_trap(): void
    {
        $surface = $this->surfaceHtml();

        // Initial focus in, focus back out (WCAG 2.4.3). The recorded no-trap decision stands: there is no
        // keydown.tab handling and nothing is made inert.
        $this->assertStringContainsString("x-init=\"\$watch('open', (v) => focusChanged(v)); if (open) focusChanged(true)\"", $surface);
        $this->assertStringContainsString('tabindex="-1"', $surface);
        $this->assertStringNotContainsString('keydown.tab', $surface);
        $this->assertStringNotContainsString('inert', $surface);
    }

    public function test_a_wrong_pin_is_announced_as_an_alert(): void
    {
        $this->surfaceHtml();

        $html = Livewire::test(TillSession::class)->set('operatorPin', '0000')->call('unlockOperator')->html();

        $this->assertMatchesRegularExpression('/data-counter-surface-feedback role="alert"/', $html);
    }
}
