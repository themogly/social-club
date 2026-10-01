<?php

namespace Tests\Feature\Counter;

use App\Actions\Staff\ClockIn;
use App\Enums\Role;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Livewire\Counter\CounterChrome;
use App\Livewire\Counter\CounterHome;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use App\Support\CounterOperator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 341 — Ben: "There's no way to see at the top if you're clocked in and when, and no button for you to clock in if
 * you're not. And it's on 2 lines as well." One row; the operator chip says who is working and their clock state
 * (Fichado 18:02 · Sin fichar · Fichado ayer 23:40), clocks in or out with the person's own PIN, and everything else is
 * in a labelled ⋯ Más. (The top-bar clock-in moved here from 338.)
 */
class TopBarClockChipTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        session(['counter.location_id' => $this->sede->id]);
        $this->ben = User::factory()->create(['name' => 'Ben', 'pin' => '1234']);
        $this->ben->assignRole(Role::OWNER->value);
        $this->ben->locations()->sync([$this->sede->id]);
        $this->ben = $this->ben->fresh();
        $this->actingAs($this->ben);
        CounterOperator::set($this->ben);
    }

    private function bar(): string
    {
        return Livewire::test(CounterChrome::class)->html();
    }

    // --- 2 + 4. The chip's states ----------------------------------------------------------------------------------------

    public function test_clocked_in_at_18_02_the_chip_says_fichado_18_02_in_green(): void
    {
        $at = CarbonImmutable::now('Europe/Madrid')->setTime(18, 2)->min(CarbonImmutable::now('Europe/Madrid'));
        (new ClockIn)->handle($this->ben, $this->sede, $this->ben, StaffClockSource::PIN, $at);

        $bar = $this->bar();
        $this->assertStringContainsString('data-clock-state="in"', $bar);
        $this->assertStringContainsString(e(__('Fichado :time', ['time' => $at->format('H:i')])), $bar);
        $this->assertStringContainsString('>Ben<', $bar);
        $this->assertStringNotContainsString('data-counter-clock-in-quick', $bar);
    }

    public function test_clocked_out_the_chip_says_sin_fichar_in_amber_with_fichar_entrada_beside_it(): void
    {
        $bar = $this->bar();
        $this->assertStringContainsString('data-clock-state="out"', $bar);
        $this->assertStringContainsString(e(__('Sin fichar')), $bar);
        $this->assertStringContainsString('data-counter-clock-in-quick', $bar);
        $this->assertStringContainsString(e(__('Fichar entrada')), $bar);
    }

    public function test_a_period_left_open_from_the_previous_business_day_shows_fichado_ayer_in_amber(): void
    {
        // The previous BUSINESS day (it rolls over in the small hours, not at midnight), mid-afternoon at the sede.
        $yesterday = CarbonImmutable::parse(BusinessDay::today($this->sede), 'Europe/Madrid')->subDay()->setTime(15, 40);
        (new ClockIn)->handle($this->ben, $this->sede, $this->ben, StaffClockSource::PIN, $yesterday);

        $bar = $this->bar();
        $this->assertStringContainsString('data-clock-state="stale"', $bar);
        $this->assertStringContainsString(e(__('Fichado ayer :time', ['time' => '15:40'])), $bar);
    }

    // --- 3. Clocking from the chip, with the PIN --------------------------------------------------------------------------------

    public function test_fichar_entrada_asks_for_the_pin_writes_a_pin_clock_in_and_the_chip_follows(): void
    {
        $home = Livewire::test(CounterHome::class)->dispatch('counter-clock-in')->assertSet('clockPrompt', 'in-pin');
        $home->set('operatorPin', '9999')->call('confirmClockIn');
        $this->assertSame(0, StaffClockEvent::query()->withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, (int) Cache::get('counter-pin:'.$this->sede->id.':attempts', 0), 'a wrong PIN must count');

        $home->set('operatorPin', '1234')->call('confirmClockIn')->assertDispatched('counter-clock-state', open: true);
        $in = StaffClockEvent::query()->withoutGlobalScopes()->sole();
        $this->assertSame([StaffClockType::IN, StaffClockSource::PIN], [$in->type, $in->source]);

        // The chrome answers the event and re-renders: the chip now says Fichado, without a page load.
        Livewire::test(CounterChrome::class)->dispatch('counter-clock-state', open: true)->assertSeeHtml('data-clock-state="in"');

        // …and Fichar salida from the chip's menu does the reverse.
        $home->dispatch('counter-clock-out')->assertSet('clockPrompt', 'out')->set('operatorPin', '1234')->call('confirmClockOut');
        $this->assertSame(1, StaffClockEvent::query()->withoutGlobalScopes()->where('type', StaffClockType::OUT->value)->count());
    }

    // --- 5. The menus --------------------------------------------------------------------------------------------------------------

    public function test_the_chip_menu_and_the_more_menu_hold_the_rest_with_labels_and_lock_and_shield_stay_out(): void
    {
        $bar = $this->bar();
        $chipMenu = $this->between($bar, 'data-counter-chip-menu', 'data-counter-chip-menu-end');
        foreach (['data-counter-clock-in', 'data-counter-my-hours', 'data-counter-switch-operator'] as $hook) {
            $this->assertStringContainsString($hook, $chipMenu, "{$hook} is not in the chip's menu");
        }
        $more = $this->between($bar, 'data-counter-more-menu', 'data-counter-more-menu-end');
        foreach (['data-counter-training' => 'Modo formación', 'data-counter-terminal' => 'Este dispositivo', 'data-counter-admin-link' => 'Administración', 'data-counter-logout' => 'Salir', 'data-counter-install' => 'Instalar como app'] as $hook => $label) {
            $this->assertStringContainsString($hook, $more, "{$hook} is not in ⋯ Más");
            $this->assertStringContainsString(e(__($label)), $more);
        }
        foreach (['data-counter-lock', 'data-counter-panic'] as $hook) {
            $this->assertStringContainsString($hook, $bar);
            $this->assertStringNotContainsString($hook, $more.$chipMenu, "{$hook} must stay visible, not in a menu");
        }
    }

    // --- 6. Mis horas: today's line ------------------------------------------------------------------------------------------------

    public function test_mis_horas_shows_todays_line(): void
    {
        (new ClockIn)->handle($this->ben, $this->sede, $this->ben, StaffClockSource::PIN, now()->subMinutes(135));
        $since = now()->subMinutes(135)->setTimezone('Europe/Madrid')->format('H:i');

        Livewire::test(CounterHome::class)->dispatch('counter-my-hours')
            ->assertSee(__('Hoy: :hours, desde :time', ['hours' => '2 h 15 min', 'time' => $since]));
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $end = strpos($html, $to);
        $this->assertNotFalse($start, "{$from} missing");
        $this->assertNotFalse($end, "{$to} missing");

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
