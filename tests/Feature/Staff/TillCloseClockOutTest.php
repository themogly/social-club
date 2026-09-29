<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Enums\TillSessionStatus;
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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 312 — "Automatically clock staff out if they close a till." The CLOSER is clocked out straight away (source
 * TILL_CLOSE, at the close's own time — their PIN closed it, so it is still their own act), with a 2-minute *Deshacer*
 * that writes an ANNUL (append-only, 281). Everyone ELSE still clocked in at the sede is listed and clocks out with their
 * own PIN — the registro de jornada's personal rule is untouched. Per sede: *Automático* (default) or *Preguntar*.
 */
class TillCloseClockOutTest extends TestCase
{
    private Organisation $org;

    private Location $sede;

    private Location $otra;

    private User $closer;

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        $this->otra = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        session(['counter.location_id' => $this->sede->id]);
        $this->closer = $this->person(Role::MANAGER, 'Ana Cierre', '4411');
        $this->actingAs($this->closer);
        CounterOperator::set($this->closer);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
    }

    private function person(Role $role, string $name, string $pin, ?Location $at = null): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin]);
        $user->assignRole($role->value);
        $user->locations()->sync([($at ?? $this->sede)->id]);

        return $user->fresh();
    }

    private function clockIn(User $user, ?Location $at = null, int $hoursAgo = 3): void
    {
        (new ClockIn)->handle($user, $at ?? $this->sede, $user, StaffClockSource::PIN, now()->subHours($hoursAgo));
    }

    private function close(): Testable
    {
        return Livewire::test(TillSession::class)->call('startClose')->set('countInput', '100')->call('submitCount')->assertSet('countSubmitted', true);
    }

    /** @return list<StaffClockEvent> */
    private function events(User $user, StaffClockType $type): array
    {
        return StaffClockEvent::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type->value)->get()->all();
    }

    // --- 1. The closer ---------------------------------------------------------------------------------------------------

    public function test_the_closer_who_is_clocked_in_is_clocked_out_at_the_close_without_a_question(): void
    {
        $this->clockIn($this->closer);
        $this->freezeTime();

        $page = $this->close()->assertSet('clockOutOffer', false)->assertSeeHtml('data-clock-out-undo');

        $out = $this->events($this->closer, StaffClockType::OUT);
        $this->assertCount(1, $out);
        $this->assertSame(StaffClockSource::TILL_CLOSE, $out[0]->source);
        $this->assertTrue($out[0]->occurred_at->equalTo(TillSessionModel::query()->withoutGlobalScopes()->sole()->closed_at));
        $this->assertNull(WorkedHours::openPeriodFor($this->closer));
        $page->assertSee(__('Salida fichada a las :time.', ['time' => local_datetime($out[0]->occurred_at, 'H:i', $this->sede)]));
    }

    public function test_a_closer_who_is_not_clocked_in_gets_nothing(): void
    {
        $this->close()->assertSet('clockOutOffer', false)->assertDontSeeHtml('data-clock-out-undo');

        $this->assertSame([], StaffClockEvent::query()->withoutGlobalScopes()->get()->all());
    }

    // --- 2. Deshacer ---------------------------------------------------------------------------------------------------------

    public function test_undo_within_two_minutes_annuls_the_clock_out_and_reopens_the_period(): void
    {
        $this->clockIn($this->closer);
        $page = $this->close();
        $this->travel(90)->seconds();

        $page->call('undoClockOut');

        $out = $this->events($this->closer, StaffClockType::OUT)[0];
        $annul = $this->events($this->closer, StaffClockType::ANNUL);
        $this->assertCount(1, $annul);
        $this->assertSame($out->id, $annul[0]->corrects_event_id);
        $this->assertNotNull(WorkedHours::openPeriodFor($this->closer), 'the period is open again');
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.clock.out']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.clock.undone']);
    }

    public function test_undo_is_refused_after_two_minutes_after_a_lock_or_when_someone_else_signs_in(): void
    {
        foreach (['late' => '4421', 'locked' => '4431', 'someone-else' => '4441'] as $case => $pin) {
            // Clock events are append-only: each case is its own closer on a freshly opened till.
            $closer = $this->person(Role::MANAGER, 'Cierre '.$case, $pin);
            CounterOperator::set($closer);
            if (TillSessionModel::query()->withoutGlobalScopes()->where('status', TillSessionStatus::OPEN->value)->doesntExist()) {
                (new OpenTill)->handle($this->sede, 'POS-1', 10000);
            }
            $this->clockIn($closer);
            $page = $this->close();

            match ($case) {
                'late' => $this->travel(121)->seconds(),
                'locked' => CounterOperator::clear(),
                'someone-else' => CounterOperator::set($this->person(Role::STAFF, 'Otra Persona', '5511')),
            };
            $page->call('undoClockOut');

            $this->assertSame([], $this->events($closer, StaffClockType::ANNUL), "undo was allowed: {$case}");
            $this->assertNull(WorkedHours::openPeriodFor($closer), "the period reopened: {$case}");
            $this->travelBack();
        }
    }

    // --- 3. Preguntar ----------------------------------------------------------------------------------------------------------

    public function test_with_ask_the_old_question_is_unchanged(): void
    {
        Settings::set('till_close_clock_out', 'ask', SettingType::STRING, $this->sede->id);
        $this->clockIn($this->closer);

        $page = $this->close()->assertSet('clockOutOffer', true)->assertSee(__('¿Fichar salida ahora?'));
        $this->assertSame([], $this->events($this->closer, StaffClockType::OUT));

        $page->call('clockOutAfterClose');
        $this->assertSame(StaffClockSource::TILL_CLOSE, $this->events($this->closer, StaffClockType::OUT)[0]->source);
    }

    // --- 4. Everyone else ---------------------------------------------------------------------------------------------------

    public function test_others_still_clocked_in_here_are_listed_and_clock_out_with_their_own_pin(): void
    {
        $marta = $this->person(Role::STAFF, 'Marta Ruiz', '6611');
        $luis = $this->person(Role::STAFF, 'Luis Gil', '7711');
        $elsewhere = $this->person(Role::STAFF, 'Nora Norte', '8811', $this->otra);
        $this->clockIn($marta, hoursAgo: 5);
        $this->clockIn($luis, hoursAgo: 2);
        $this->clockIn($elsewhere, $this->otra);

        $page = $this->close();
        $listed = array_column($page->instance()->stillClockedIn(), 'name');
        $this->assertEqualsCanonicalizing(['Marta', 'Luis'], $listed);
        $page->assertSee(__('Aún con jornada abierta'))->assertDontSee('Nora');

        $before = (new UnlockOperator)->attemptsRemaining($this->sede, 'counter-pin:'.$this->sede->id);
        $page->call('startClockOutFor', $marta->id)->set('otherPin', '0000')->call('confirmClockOutFor');
        $this->assertSame([], $this->events($marta, StaffClockType::OUT), 'a wrong PIN clocked someone out');
        $this->assertLessThan($before, (new UnlockOperator)->attemptsRemaining($this->sede, 'counter-pin:'.$this->sede->id), 'a wrong PIN did not count');

        $page->call('startClockOutFor', $marta->id)->set('otherPin', '7711')->call('confirmClockOutFor');
        $this->assertSame([], $this->events($marta, StaffClockType::OUT), "Luis's PIN clocked Marta out");
        $this->assertSame([], $this->events($luis, StaffClockType::OUT), "Luis's PIN clocked Luis out from Marta's row");

        $page->call('startClockOutFor', $marta->id)->set('otherPin', '6611')->call('confirmClockOutFor');
        $out = $this->events($marta, StaffClockType::OUT);
        $this->assertCount(1, $out);
        $this->assertSame(StaffClockSource::PIN, $out[0]->source);
        $this->assertSame($marta->id, $out[0]->recorded_by);
        $this->assertSame(['Luis'], array_column($page->instance()->stillClockedIn(), 'name'));
        $this->assertSame($this->closer->id, CounterOperator::id(), 'a colleague\'s PIN signed them in at the counter');
    }

    public function test_the_personal_rule_still_refuses_clocking_someone_else_out(): void
    {
        $marta = $this->person(Role::STAFF, 'Marta Ruiz', '6611');
        $this->clockIn($marta);

        $this->expectException(AuthorizationException::class);
        (new ClockOut)->handle($marta, $this->closer, StaffClockSource::TILL_CLOSE);
    }

    // --- 6. The close itself ----------------------------------------------------------------------------------------------------

    public function test_the_close_is_the_same_whether_the_clock_out_succeeds_fails_or_is_undone(): void
    {
        $figures = [];
        foreach (['auto' => '4451', 'no-period' => '4461', 'undone' => '4471'] as $case => $pin) {
            $closer = $this->person(Role::MANAGER, 'Cierre '.$case, $pin);
            CounterOperator::set($closer);
            if (TillSessionModel::query()->withoutGlobalScopes()->where('status', TillSessionStatus::OPEN->value)->doesntExist()) {
                (new OpenTill)->handle($this->sede, 'POS-1', 10000);
            }
            if ($case !== 'no-period') {
                $this->clockIn($closer);
            }
            $page = $this->close();
            if ($case === 'undone') {
                $page->call('undoClockOut');
            }
            $session = TillSessionModel::query()->withoutGlobalScopes()->latest('closed_at')->latest('id')->first();
            $figures[$case] = [$session->status, $session->counted_cents?->cents, $session->expected_cents?->cents, $session->variance_cents?->cents];
        }

        $this->assertSame([TillSessionStatus::CLOSED, 10000, 10000, 0], $figures['auto']);
        $this->assertSame($figures['auto'], $figures['no-period']);
        $this->assertSame($figures['auto'], $figures['undone']);
    }
}
