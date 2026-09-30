<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Actions\Staff\ClockRules;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Livewire\Counter\CounterChrome;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StaffClockEvent;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\WorkedHours;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 338 — Ben: "A check-in button at the top for the staff. It just allowed me to close and then open a till, and
 * didn't ask me to check in." Opening a till clocks the opener in (TILL_OPEN, their own act) — or asks — under ONE
 * per-sede setting for both ends of the till. (The top bar's own «Fichar entrada» is prompt 341's.)
 */
class TillOpenClockInTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        session(['counter.location_id' => $this->sede->id]);
        $this->staff = User::factory()->create(['name' => 'Marta Ruiz', 'pin' => '3456']);
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->sede->id]);
        $this->staff = $this->staff->fresh();
        $this->actingAs($this->staff);
        CounterOperator::set($this->staff);
    }

    /** @return list<StaffClockEvent> */
    private function events(StaffClockType $type): array
    {
        return StaffClockEvent::query()->withoutGlobalScopes()->where('user_id', $this->staff->id)->where('type', $type->value)->get()->all();
    }

    private function openTill(): void
    {
        Livewire::test(TillSession::class)->set('floatInput', '50')->call('open')->assertRedirect();
    }

    // --- 2–4. Opening a till -------------------------------------------------------------------------------------------------

    public function test_automatico_opening_clocks_the_opener_in_with_an_undo_that_annuls(): void
    {
        (new ClockIn)->handle($this->staff, $this->sede, $this->staff, StaffClockSource::PIN, now()->subHours(8));
        (new ClockOut)->handle($this->staff, $this->staff, StaffClockSource::TILL_CLOSE, now()->subMinute()); // 312's close

        $this->openTill();
        $till = TillSessionModel::query()->withoutGlobalScopes()->where('status', 'OPEN')->sole();
        $in = collect($this->events(StaffClockType::IN))->firstWhere('source', StaffClockSource::TILL_OPEN);
        $this->assertNotNull($in, 'no TILL_OPEN clock-in');
        $this->assertTrue($in->occurred_at->equalTo($till->opened_at));

        $chrome = Livewire::test(CounterChrome::class)
            ->assertSee(__('Entrada fichada a las :time.', ['time' => local_datetime($till->opened_at, 'H:i', $this->sede)]))->assertSee(__('Deshacer'));
        // Undone: an ANNUL, the period gone, and `counter-clock-state` says so — the top bar's clock status follows it
        // (its «Fichar entrada» is prompt 341's).
        $chrome->call('undoClockIn')->assertDispatched('counter-clock-state', open: false);
        $this->assertTrue(StaffClockEvent::query()->withoutGlobalScopes()->where('type', StaffClockType::ANNUL->value)->where('corrects_event_id', $in->id)->exists());
        $this->assertNull(WorkedHours::openPeriodFor($this->staff));
    }

    public function test_preguntar_asks_and_si_clocks_in_while_no_writes_nothing(): void
    {
        Settings::set('till_close_clock_out', 'ask', SettingType::STRING, $this->sede->id);

        $this->openTill();
        $this->assertSame([], $this->events(StaffClockType::IN));
        Livewire::test(CounterChrome::class)->assertSee(__('¿Fichar entrada ahora?'))->call('declineClockIn')->assertDontSee(__('¿Fichar entrada ahora?'));
        $this->assertSame([], $this->events(StaffClockType::IN));

        TillSessionModel::query()->withoutGlobalScopes()->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $this->openTill();
        Livewire::test(CounterChrome::class)->assertSee(__('¿Fichar entrada ahora?'))->call('acceptClockIn');
        $this->assertSame([StaffClockSource::TILL_OPEN], array_map(fn ($e) => $e->source, $this->events(StaffClockType::IN)));
    }

    public function test_an_opener_already_clocked_in_gets_no_second_clock_in(): void
    {
        (new ClockIn)->handle($this->staff, $this->sede, $this->staff);
        $this->openTill();

        $this->assertCount(1, $this->events(StaffClockType::IN));
        Livewire::test(CounterChrome::class)->assertDontSee(__('Deshacer'))->assertDontSee(__('¿Fichar entrada ahora?'));
    }

    // --- 5–6. The rules and the report -----------------------------------------------------------------------------------------

    public function test_till_open_is_a_personal_source(): void
    {
        ClockRules::authorise($this->staff, $this->sede, $this->staff, StaffClockSource::TILL_OPEN, null);

        $other = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        ClockRules::authorise($this->staff, $this->sede, $other, StaffClockSource::TILL_OPEN, null);
    }

    public function test_the_hours_report_shows_a_till_open_period_as_ordinary_clocked_time(): void
    {
        (new ClockIn)->handle($this->staff, $this->sede, $this->staff, StaffClockSource::TILL_OPEN, now()->subHours(3));
        (new ClockOut)->handle($this->staff, $this->staff, StaffClockSource::PIN);

        $this->assertFalse(StaffClockSource::TILL_OPEN->isDeclared());
        $this->assertSame(__('Al abrir la caja'), StaffClockSource::TILL_OPEN->label());
        $period = collect(WorkedHours::periods([$this->staff->id], [$this->sede->id], now()->subDay(), now()->addDay()))->sole();
        $this->assertSame([], $period['flags']);
        $this->assertSame(180, $period['minutes']);
    }
}
