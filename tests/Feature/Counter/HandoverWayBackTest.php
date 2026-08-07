<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
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
 * Prompt 187, defect 2 — handed-over mode shipped with no way out.
 *
 * Reported: "if I close the form I get stuck on this page." Prompt 174 sends the applicant to the real
 * tokenised form, so this surface is what shows when they LEAVE it — and all it contained was a heading, a
 * sentence, and a box promising "El formulario se abrirá aquí": a form that was never coming. No button, no
 * pad, no control of any kind. 173 required "the PIN is how you get back" and asked that aborting not be
 * harder than completing; as built, aborting was impossible without clearing the session.
 *
 * These assert the way back exists on EVERY counter screen (the mode is terminal-wide, the form is not),
 * that it actually returns the counter, and that none of 173's guarantees were spent buying it.
 */
class HandoverWayBackTest extends TestCase
{
    use RefreshDatabase;

    /** All five include the surface; a gap in one is a gap in all of them. */
    private const SCREENS = [
        CheckInScreen::class,
        MembershipCounter::class,
        TillSession::class,
        DispensaryPos::class,
        BarPos::class,
    ];

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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
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

    private function handOver(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');
        $this->assertTrue(CounterHandover::active(), 'precondition: the tablet is handed over');
    }

    // --- The way back exists, everywhere -------------------------------------------------------------

    public function test_every_counter_screen_renders_a_way_back_during_a_handover(): void
    {
        $this->handOver();

        foreach (self::SCREENS as $screen) {
            $html = Livewire::actingAs($this->device)->test($screen)->html();

            $this->assertStringContainsString('data-handover-staff', $html,
                class_basename($screen).' offers no way out of handed-over mode.');
            $this->assertStringContainsString('data-surface-mode="handover"', $html);
        }
    }

    public function test_the_way_back_is_a_real_labelled_control_not_a_hidden_gesture(): void
    {
        $this->handOver();

        $html = Livewire::actingAs($this->device)->test(CheckInScreen::class)->html();

        // Focusable, labelled, and reachable by keyboard or assistive tech — an invisible long-press would
        // be none of those, and undiscoverable besides.
        $this->assertMatchesRegularExpression('/<button[^>]*data-handover-staff/', $html);
        $this->assertStringContainsString(__('Personal del club'), $html);
    }

    public function test_the_resting_state_no_longer_promises_a_form_that_is_not_coming(): void
    {
        $this->handOver();

        $html = Livewire::actingAs($this->device)->test(CheckInScreen::class)->html();

        // Through __(), because the suite runs in English — asserting the Spanish SOURCE string here
        // would pass against unfixed main for the wrong reason (it renders the translation, not the key).
        $this->assertStringNotContainsString(__('El formulario se abrirá aquí.'), $html);
        // It says the true thing instead: hand the tablet back when you are done.
        $this->assertStringContainsString(__('Rellena tus datos en esta tablet. Cuando termines, devuélvela al personal.'), $html);
    }

    public function test_the_pin_from_handed_over_mode_returns_the_counter(): void
    {
        $this->handOver();

        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->set('operatorPin', '4321')
            ->call('unlockOperator');

        $this->assertFalse(CounterHandover::active(), 'the handover did not end');
        $this->assertSame($this->operator->id, CounterOperator::id(), 'the counter did not come back');
    }

    public function test_a_wrong_pin_from_handed_over_mode_uses_the_same_throttle(): void
    {
        $this->handOver();

        // Same unlockOperator call, so the same UnlockOperator throttle — handed-over mode is not a softer
        // way in. Hammer it, then confirm even the CORRECT PIN is refused.
        $screen = Livewire::actingAs($this->device)->test(CheckInScreen::class);
        for ($i = 0; $i < 10; $i++) {
            $screen->set('operatorPin', '0000')->call('unlockOperator');
        }

        $screen->set('operatorPin', '4321')->call('unlockOperator')
            ->assertSet('operatorFeedback', __('Demasiados intentos. Espera un momento antes de reintentar.'));

        $this->assertTrue(CounterHandover::active(), 'a throttled attempt must not end the handover');
    }

    // --- and none of 173's guarantees were spent buying it -------------------------------------------

    public function test_the_new_control_leaks_nothing_of_the_clubs(): void
    {
        $this->handOver();

        foreach (self::SCREENS as $screen) {
            $html = Livewire::actingAs($this->device)->test($screen)->html();

            $this->assertStringNotContainsString('Marta Operadora', $html, 'The operator is named.');
            $this->assertStringNotContainsString($this->location->name, $html, 'The sede is named.');
            $this->assertStringNotContainsString('data-operator-name', $html);
            $this->assertStringNotContainsString('data-counter-topbar', $html, 'The counter chrome is back.');
        }
    }

    public function test_the_idle_timer_during_a_handover_still_lands_on_locked(): void
    {
        $this->handOver();

        Livewire::actingAs($this->device)->test(CheckInScreen::class)->call('lockCounter');

        $this->assertFalse(CounterHandover::active());
        $this->assertNull(CounterOperator::id());
    }

    public function test_the_back_button_still_does_not_return_the_counter(): void
    {
        $this->handOver();

        // A full-page GET is what the back button issues. It must still render the surface, not the counter.
        $html = $this->actingAs($this->device)->get(route('counter.checkin'))->assertOk()->getContent();

        $this->assertStringContainsString('data-surface-mode="handover"', $html);
        $this->assertStringNotContainsString('data-counter-topbar', $html);
    }
}
