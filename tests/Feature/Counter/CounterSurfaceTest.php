<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 173 — the counter's one full-screen surface, in three modes.
 *
 * The reported bug, measured: the operator strip was a normal-flow block, 49px closed and 521px open, so
 * opening the PIN pad pushed everything below it down. On the till at 1180×820 "Abrir caja" moved from
 * y=381 (50% down) to y=805 (102%) and never came back — you tapped Identificarse to be allowed to press
 * the button, and the button left the screen.
 *
 * And the drift this was meant to prevent had already happened: `operator-strip` and `lock-overlay` each
 * carried their own PIN pad with character-identical Alpine state, both included by all five screens. This
 * branch deletes the second one rather than avoiding it.
 */
class CounterSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $device;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->device = $this->staff('Device Login', null);
        $this->operator = $this->staff('Marta Operadora', '4321');
    }

    private function staff(string $name, ?string $pin): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    // --- Exactly one PIN pad, forever ---------------------------------------------------------------

    public function test_exactly_one_pin_pad_exists_in_the_codebase(): void
    {
        // Asserted by enumeration so the pair that existed today cannot quietly become a pair again.
        $pads = [];
        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $ignored) {
            // (glob is not recursive enough on its own; the iterator below does the walking)
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'push(d) { if (this.pin.length < 8)')
                || str_contains((string) file_get_contents($file->getPathname()), 'push(d){ if (this.pin.length < 8)')) {
                $pads[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }

        $this->assertSame(['livewire/counter/partials/counter-surface.blade.php'], $pads,
            'There must be exactly ONE PIN pad. Two partials each grew their own once already.');
    }

    public function test_the_retired_partials_are_gone_from_every_screen(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/livewire/counter/partials/operator-strip.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/livewire/counter/partials/lock-overlay.blade.php'));

        foreach (['dispensary-pos', 'check-in-screen', 'till-session', 'bar-pos', 'membership-counter'] as $screen) {
            $blade = (string) file_get_contents(resource_path("views/livewire/counter/{$screen}.blade.php"));
            $this->assertStringContainsString('counter-surface', $blade, "{$screen} does not render the surface.");
            $this->assertStringNotContainsString('operator-strip', $blade, "{$screen} still includes the retired strip.");
        }
    }

    // --- The reported bug ------------------------------------------------------------------------------

    public function test_identifying_does_not_move_the_till_button_because_the_surface_is_fixed(): void
    {
        // The strip was in NORMAL FLOW, so opening it displaced the page. The surface is fixed inset-0, so
        // it cannot displace anything — asserted structurally, since the pixel measurement needs a browser.
        $surface = (string) file_get_contents(resource_path('views/livewire/counter/partials/counter-surface.blade.php'));

        $this->assertStringContainsString('fixed inset-0', $surface);
        $this->assertStringNotContainsString('border-b border-line bg-surface-alt px-4 py-2', $surface,
            'The surface must not reintroduce the normal-flow strip that displaced the page.');
    }

    public function test_the_surface_is_opaque(): void
    {
        // Prompt 120's entry claimed an opaque surface while the markup painted bg-surface-alt/95 with a
        // blur. In handed-over mode a non-member holds the tablet with the counter behind them.
        $surface = (string) file_get_contents(resource_path('views/livewire/counter/partials/counter-surface.blade.php'));

        // The rendered class attribute only — the docblock above it explains the translucent values it
        // replaced, and must not be mistaken for them.
        preg_match('/\n\s+class="(fixed inset-0[^"]*)"/', $surface, $m);
        $this->assertNotEmpty($m, 'The surface root must carry a fixed inset-0 class attribute.');

        $this->assertStringContainsString('bg-surface-alt', $m[1]);
        $this->assertStringNotContainsString('/95', $m[1], 'The surface must be opaque, not 95%.');
        $this->assertStringNotContainsString('backdrop-blur', $m[1]);
    }

    // --- The three modes ---------------------------------------------------------------------------------

    public function test_no_operator_puts_the_surface_in_unidentified_mode(): void
    {
        Livewire::actingAs($this->device)->test(TillSession::class)
            ->assertSee('data-surface-mode="unidentified"', false);
    }

    public function test_an_identified_operator_sees_no_surface(): void
    {
        CounterOperator::set($this->operator);

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->assertSee('data-surface-mode="none"', false);
    }

    public function test_unidentified_then_pin_sets_the_operator_and_clears_the_surface(): void
    {
        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertSee('data-surface-mode="none"', false);

        $this->assertSame($this->operator->id, CounterOperator::id());
    }

    public function test_handover_outranks_every_other_mode(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $this->assertTrue(CounterHandover::active());

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->assertSee('data-surface-mode="handover"', false);
    }

    // --- Handed-over guarantees ---------------------------------------------------------------------------

    public function test_handover_removes_the_counters_chrome_from_the_dom(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $html = $this->actingAs($this->device)->get(route('counter.till'))->assertOk()->getContent();

        // ABSENT, not merely hidden: no tab strip, no overflow menu, no Panel link, no Log out.
        $this->assertStringNotContainsString('data-counter-topbar', $html);
        $this->assertStringNotContainsString('data-counter-overflow', $html);
        $this->assertStringNotContainsString(route('filament.admin.auth.logout'), $html);
        $this->assertStringNotContainsString('counter.panic', $html);
    }

    public function test_nothing_of_the_clubs_is_on_screen_during_handover(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $html = $this->actingAs($this->device)->get(route('counter.till'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Marta Operadora', $html, 'The operator must not be named.');
        $this->assertStringNotContainsString($this->location->name, $html, 'The sede must not be named.');
        $this->assertStringNotContainsString('data-operator-name', $html);
    }

    public function test_every_counter_route_refuses_to_show_its_screen_during_handover(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        foreach (['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'] as $route) {
            $html = $this->actingAs($this->device)->get(route($route))->getContent();
            $this->assertStringNotContainsString('data-counter-topbar', $html,
                "[{$route}] rendered counter chrome during a handover.");
        }
    }

    public function test_the_pin_ends_the_handover(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');
        $this->assertTrue(CounterHandover::active());

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('operatorPin', '4321')
            ->call('unlockOperator');

        $this->assertFalse(CounterHandover::active());
        $this->assertSame($this->operator->id, CounterOperator::id());
    }

    public function test_the_idle_timer_during_handover_lands_on_locked_not_on_the_counter(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        Livewire::actingAs($this->device)->test(TillSession::class)->call('lockCounter');

        // Handover ended AND no operator — so the surface stays up and the counter is not returned to.
        $this->assertFalse(CounterHandover::active());
        $this->assertNull(CounterOperator::id());
        Livewire::actingAs($this->device)->test(TillSession::class)
            ->assertSee('data-surface-mode="unidentified"', false);
    }

    public function test_nothing_survives_from_one_handover_into_the_next(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');
        session(['counter.handover.draft' => ['document_number' => '12345678Z']]);

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('operatorPin', '4321')->call('unlockOperator');

        $this->assertNull(session('counter.handover.draft'), "The last applicant's draft survived the handover.");
    }

    public function test_beginning_and_ending_a_handover_are_audited(): void
    {
        CounterOperator::set($this->operator);
        $this->actingAs($this->device);

        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');
        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('operatorPin', '4321')->call('unlockOperator');

        foreach (['counter.handover.started', 'counter.handover.ended'] as $action) {
            $this->assertTrue(
                AuditLog::query()->withoutGlobalScopes()->where('action', $action)->exists(),
                "[{$action}] was not audited."
            );
        }
    }

    public function test_handover_cannot_be_entered_without_an_operator(): void
    {
        // Entered only from the counter by an IDENTIFIED operator — never by URL, and never by a tap from
        // an unidentified device.
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $this->assertFalse(CounterHandover::active());
    }

    // --- The throttle is not softer in any mode ------------------------------------------------------------

    public function test_a_wrong_pin_in_handover_uses_the_same_lockout(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $screen = Livewire::actingAs($this->device)->test(TillSession::class);
        for ($i = 0; $i < 5; $i++) {
            $screen->set('operatorPin', '0000')->call('unlockOperator');
        }

        // Even the correct PIN is refused while locked out — handover has not become a softer way in.
        $screen->set('operatorPin', '4321')->call('unlockOperator')
            ->assertSet('operatorFeedback', __('Demasiados intentos. Espera un momento antes de reintentar.'));

        $this->assertTrue(CounterHandover::active());
    }

    // --- The server-side gate is still the real one ---------------------------------------------------------

    public function test_a_write_is_still_refused_with_no_operator_even_with_the_surface_bypassed(): void
    {
        // The surface is presentation. requireOperator() is the boundary, and beginning a handover signs
        // the operator out precisely so a stale tab cannot commit.
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');

        $this->assertNull(CounterOperator::id());

        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('terminal', 'POS-1')->set('floatInput', '100')->call('open');

        $this->assertDatabaseCount('till_sessions', 0);
    }
}
