<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\AnnulClockEvent;
use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Filament\Pages\RegistroJornada;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Permissions;
use App\Support\Settings;
use App\Support\WorkedHours;
use App\ViewModels\Rat;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 281 (Ben's 280) — Registro de jornada: staff clock in and out with their PIN.
 *
 * Hours are their own append-only event log, confirmed by that person's PIN — never derived from sign-ins (the idle lock
 * makes dozens a night) or from TillShift (who held the drawer is not who worked). Built to the stricter digital-record
 * standard (personal, unalterable, 4 years) whether or not the regime applies to compensated volunteers.
 */
class RegistroDeJornadaTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        Artisan::call('csc:sync-permissions');
        $this->travelTo(CarbonImmutable::parse('2026-09-26 18:00', 'Europe/Madrid'));
        $this->org = Organisation::factory()->create(['legal_name' => 'Asociación Prueba']);
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00']);
        app(ActiveScope::class)->setLocation($this->centro->id);

        $this->owner = $this->user(Role::OWNER, '1111');
        $this->manager = $this->user(Role::MANAGER, '2222');
        $this->staff = $this->user(Role::STAFF, '3333');
        (new OpenTill)->handle($this->centro, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
    }

    private function user(Role $role, string $pin, ?Location $at = null): User
    {
        $user = User::factory()->create(['pin' => Hash::make($pin), 'active' => true]);
        $user->assignRole($role->value);
        $user->locations()->attach(($at ?? $this->centro)->id);

        return $user;
    }

    private function at(string $madrid): CarbonImmutable
    {
        return CarbonImmutable::parse($madrid, 'Europe/Madrid');
    }

    /** A counter tablet at Centro, signed in once as the owner, nobody at the PIN. */
    private function tablet(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('counter.location'), ['location_id' => $this->centro->id]);
        CounterOperator::clear();
    }

    private function till(): string
    {
        return $this->snapshotFrom('/counter/till', 'counter.till-session');
    }

    private function state($response): array
    {
        return json_decode(json_decode($response->getContent(), true)['components'][0]['snapshot'], true)['data'];
    }

    // --- 1–4. Data, immutability, pairing ------------------------------------------------------------------------------

    public function test_a_clock_event_cannot_be_updated_or_deleted(): void
    {
        $event = (new ClockIn)->handle($this->staff, $this->centro, $this->staff);

        foreach ([fn () => $event->update(['reason' => 'x']), fn () => $event->delete(),
            fn () => StaffClockEvent::query()->whereKey($event->id)->update(['reason' => 'x']),
            fn () => StaffClockEvent::query()->whereKey($event->id)->delete()] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("mutation path {$i} was allowed");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, StaffClockEvent::query()->count());
    }

    public function test_worked_hours_pairs_events_skips_annulled_and_reports_open_periods(): void
    {
        $this->travelTo($this->at('2026-09-26 10:00'));
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelTo($this->at('2026-09-26 14:00'));
        (new ClockOut)->handle($this->staff, $this->staff, StaffClockSource::PIN);
        $this->travelTo($this->at('2026-09-26 15:00'));
        $wrong = (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        (new AnnulClockEvent)->handle($wrong, $this->manager, 'Fichaje por error');
        $this->travelTo($this->at('2026-09-26 16:00'));
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);

        $periods = WorkedHours::periods([$this->staff->id], [$this->centro->id], $this->at('2026-09-01'), $this->at('2026-10-01'));

        $this->assertCount(2, $periods);
        $this->assertSame(240, $periods[0]['minutes']);
        $this->assertSame('complete', $periods[0]['status']);
        $this->assertSame('open', $periods[1]['status']);
        $this->assertNotNull(WorkedHours::openPeriodFor($this->staff));
    }

    public function test_a_shift_past_midnight_but_before_the_cutoff_is_one_business_day(): void
    {
        $this->travelTo($this->at('2026-09-26 20:00'));
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelTo($this->at('2026-09-27 02:30'));
        (new ClockOut)->handle($this->staff, $this->staff, StaffClockSource::PIN);

        $events = StaffClockEvent::query()->get();
        $this->assertSame(['2026-09-26', '2026-09-26'], $events->map(fn (StaffClockEvent $e): string => $e->business_date->toDateString())->all());
        $periods = WorkedHours::periods([$this->staff->id], [$this->centro->id], $this->at('2026-09-01'), $this->at('2026-10-01'));
        $this->assertSame('2026-09-26', $periods[0]['business_date']);
        $this->assertSame(390, $periods[0]['minutes']);
    }

    public function test_clocking_in_while_open_at_another_sede_is_refused_naming_it(): void
    {
        $this->staff->locations()->attach($this->norte->id);
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);

        try {
            (new ClockIn)->handle($this->staff, $this->norte, $this->staff);
            $this->fail('clocked in twice');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Sede Centro', $e->getMessage());
        }
    }

    // --- 5–8. Clocking in on the lock surface ---------------------------------------------------------------------------

    public function test_a_pin_unlock_with_no_open_period_asks_and_writes_nothing_until_answered(): void
    {
        $this->tablet();
        $response = $this->livewirePost($this->till(), ['operatorPin' => '3333'], [['unlockOperator']])->assertOk();

        $this->assertSame('in', $this->state($response)['clockPrompt']);
        $this->assertSame(0, StaffClockEvent::query()->count());

        $clockedIn = $this->livewirePost($this->till(), [], [['clockInNow']])->assertOk();
        // The top bar is drawn once per page: "Fichar salida" follows this browser event, not the next page load.
        $this->assertStringContainsString('counter-clock-state', (string) $clockedIn->getContent());
        $event = StaffClockEvent::query()->sole();
        $this->assertSame(StaffClockType::IN, $event->type);
        $this->assertSame(StaffClockSource::PIN, $event->source);
        $this->assertSame($this->staff->id, $event->user_id);
        $this->assertTrue(AuditLog::query()->where('action', 'staff.clock.in')->exists());
    }

    public function test_solo_identificarme_signs_in_and_writes_no_event(): void
    {
        $this->tablet();
        $this->livewirePost($this->till(), ['operatorPin' => '2222'], [['unlockOperator']])->assertOk();
        $this->livewirePost($this->till(), [], [['skipClockIn']])->assertOk();

        $this->assertSame($this->manager->id, CounterOperator::id());
        $this->assertSame(0, StaffClockEvent::query()->count());
    }

    public function test_the_idle_unlock_with_an_open_period_asks_nothing_cycle_after_cycle(): void
    {
        $this->tablet();
        $this->livewirePost($this->till(), ['operatorPin' => '3333'], [['unlockOperator']]);
        $this->livewirePost($this->till(), [], [['clockInNow']]);

        foreach (range(1, 4) as $cycle) {
            $this->livewirePost($this->till(), calls: [['__dispatch', ['counter-lock', []]]])->assertOk();
            $this->assertNull(CounterOperator::id());
            $response = $this->livewirePost($this->till(), ['operatorPin' => '3333'], [['unlockOperator']])->assertOk();
            $this->assertNull($this->state($response)['clockPrompt'], "cycle {$cycle} asked again");
        }
        $this->assertSame(1, StaffClockEvent::query()->count());
    }

    public function test_the_supervisor_pin_never_asks_or_clocks_anyone_in(): void
    {
        // The supervisor PIN authorises one act and is not a sign-in (265): it goes through authoriserFromPin, never
        // unlockOperator — so it cannot reach the clock question at all.
        $this->assertFalse(method_exists(IdentifiesOperator::class, 'authoriserClocks'));
        $source = (string) file_get_contents(app_path('Livewire/Counter/Concerns/IdentifiesOperator.php'));
        preg_match('/function authoriserFromPin.*?\n    }\n/s', $source, $m);
        $this->assertStringNotContainsString('clock', strtolower($m[0] ?? ''));
    }

    // --- 9–12. Clocking out ----------------------------------------------------------------------------------------------

    private function clockedInStaff(): void
    {
        $this->tablet();
        $this->livewirePost($this->till(), ['operatorPin' => '3333'], [['unlockOperator']]);
        $this->livewirePost($this->till(), [], [['clockInNow']]);
    }

    public function test_fichar_salida_with_a_wrong_pin_writes_nothing_and_counts(): void
    {
        $this->clockedInStaff();
        $this->livewirePost($this->till(), calls: [['__dispatch', ['counter-clock-out', []]]])->assertOk();
        $this->livewirePost($this->till(), ['operatorPin' => '9999'], [['confirmClockOut']])->assertOk();

        $this->assertSame(1, StaffClockEvent::query()->count());
        $this->assertSame(1, (new UnlockOperator)->statusFor('counter-pin:'.$this->centro->id)['attempts']);
    }

    public function test_fichar_salida_with_another_persons_pin_is_refused(): void
    {
        $this->clockedInStaff();
        $this->livewirePost($this->till(), calls: [['__dispatch', ['counter-clock-out', []]]]);
        $this->livewirePost($this->till(), ['operatorPin' => '2222'], [['confirmClockOut']])->assertOk();

        $this->assertSame(1, StaffClockEvent::query()->count());
        $this->assertSame($this->staff->id, CounterOperator::id());
    }

    public function test_after_fichar_salida_the_counter_is_locked(): void
    {
        $this->clockedInStaff();
        $this->livewirePost($this->till(), calls: [['__dispatch', ['counter-clock-out', []]]]);
        $this->livewirePost($this->till(), ['operatorPin' => '3333'], [['confirmClockOut']])->assertOk();

        $out = StaffClockEvent::query()->where('type', StaffClockType::OUT->value)->sole();
        $this->assertSame(StaffClockSource::PIN, $out->source);
        $this->assertNull(CounterOperator::id(), 'the counter was left signed in after clocking out');
    }

    public function test_closing_the_till_offers_clock_out_and_yes_writes_till_close(): void
    {
        // Prompt 312 — the question is now the per-sede *Preguntar* choice (the default clocks the closer out automatically).
        Settings::set('till_close_clock_out', 'ask', SettingType::STRING, $this->centro->id);
        (new ClockIn)->handle($this->owner, $this->centro, $this->owner);
        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->centro->id]);
        CounterOperator::set($this->owner);

        Livewire::test(TillScreen::class)
            ->call('startClose')->set('reweighing', false)
            ->set('countInput', '100')->call('submitCount')
            ->assertSet('clockOutOffer', true)
            ->call('clockOutAfterClose');

        $this->assertSame(StaffClockSource::TILL_CLOSE, StaffClockEvent::query()->where('type', StaffClockType::OUT->value)->sole()->source);
    }

    // --- 13–16. Forgotten clock-outs and corrections --------------------------------------------------------------------

    public function test_no_scheduled_command_writes_a_clock_out(): void
    {
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelTo($this->at('2026-09-27 07:00'));

        Artisan::call('schedule:run');
        foreach (['members:purge', 'audit:redact-retention'] as $command) {
            Artisan::call($command);
        }

        $this->assertSame(0, StaffClockEvent::query()->where('type', StaffClockType::OUT->value)->count());
        $this->assertNotNull(WorkedHours::openPeriodFor($this->staff));
    }

    public function test_a_forgotten_clock_out_is_declared_before_the_next_clock_in(): void
    {
        $this->travelTo($this->at('2026-09-26 18:00'));
        $in = (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelTo($this->at('2026-09-27 19:00'));

        $this->tablet();
        $response = $this->livewirePost($this->till(), ['operatorPin' => '3333'], [['unlockOperator']]);
        $this->assertSame('declare', $this->state($response)['clockPrompt']);

        // No time → refused in plain words (never the date parser's own message).
        $empty = $this->livewirePost($this->till(), ['declaredEnd' => '', 'declaredReason' => 'Olvido'], [['declareForgottenEnd']]);
        $this->assertSame('Indica la hora a la que terminaste.', $this->state($empty)['clockFeedback']);

        // Outside the period (before its IN) → refused; no reason → refused.
        $this->livewirePost($this->till(), ['declaredEnd' => '2026-09-26T17:00', 'declaredReason' => 'Olvido'], [['declareForgottenEnd']]);
        $this->livewirePost($this->till(), ['declaredEnd' => '2026-09-26T23:30', 'declaredReason' => ''], [['declareForgottenEnd']]);
        $this->assertSame(1, StaffClockEvent::query()->count());

        $this->livewirePost($this->till(), ['declaredEnd' => '2026-09-26T23:30', 'declaredReason' => 'Me olvidé de fichar'], [['declareForgottenEnd']])->assertOk();

        $events = StaffClockEvent::query()->orderBy('recorded_at')->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame(StaffClockSource::SELF_DECLARED, $events[1]->source);
        $this->assertTrue($events[1]->occurred_at->equalTo($this->at('2026-09-26 23:30')));
        $this->assertTrue($events[1]->recorded_at->greaterThan($events[1]->occurred_at));
        $this->assertSame(StaffClockType::IN, $events[2]->type);
        $this->assertSame($in->id, $events[0]->id);
    }

    public function test_a_manager_correction_and_an_annulment_add_rows_and_need_a_reason(): void
    {
        $in = (new ClockIn)->handle($this->staff, $this->centro, $this->staff);

        try {
            (new ClockOut)->handle($this->staff, $this->manager, StaffClockSource::MANAGER_CORRECTION, $this->at('2026-09-26 22:00'), '');
            $this->fail('a correction without a reason');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->travelTo($this->at('2026-09-27 10:00'));
        $out = (new ClockOut)->handle($this->staff, $this->manager, StaffClockSource::MANAGER_CORRECTION, $this->at('2026-09-26 22:00'), 'Olvidó fichar la salida');
        $annul = (new AnnulClockEvent)->handle($out, $this->manager, 'Hora equivocada');

        $this->assertSame(3, StaffClockEvent::query()->count());
        $this->assertSame($out->id, $annul->corrects_event_id);
        $this->assertSame($in->occurred_at->toIso8601String(), $in->fresh()->occurred_at->toIso8601String());
        $this->assertTrue(AuditLog::query()->where('action', 'staff.clock.annulled')->exists());
    }

    public function test_corrections_need_the_permission_and_the_sede(): void
    {
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $elsewhere = $this->user(Role::MANAGER, '4444', $this->norte);

        foreach ([$this->staff, $elsewhere] as $actor) {
            try {
                (new ClockOut)->handle($this->staff, $actor, StaffClockSource::MANAGER_CORRECTION, now(), 'Corrección');
                $this->fail('a correction by someone without the permission at this sede');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actingAs($elsewhere);
        app(ActiveScope::class)->setLocation($this->norte->id);
        $rows = Livewire::test(RegistroJornada::class)->invade()->reportRows();
        $this->assertSame([], $rows, 'a manager saw a sede they are not assigned to');
    }

    // --- 17–18. Visibility --------------------------------------------------------------------------------------------------

    public function test_mis_horas_shows_only_the_signed_in_persons_hours(): void
    {
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelTo($this->at('2026-09-26 19:00'));
        (new ClockIn)->handle($this->owner, $this->centro, $this->owner);

        $this->tablet();
        $this->livewirePost($this->till(), ['operatorPin' => '1111'], [['unlockOperator']]);
        $html = (string) json_decode($this->livewirePost($this->till(), calls: [['__dispatch', ['counter-my-hours', []]]])->getContent(), true)['components'][0]['effects']['html'];

        $this->assertStringContainsString('data-my-hours', $html);
        $this->assertSame(1, substr_count($html, 'data-my-hours-row'), 'Mis horas showed someone else\'s hours');
    }

    public function test_staff_cannot_open_the_registro_de_jornada(): void
    {
        $this->assertFalse(RegistroJornada::canAccess() && $this->staff->can('staff.hours.view'));
        $this->actingAs($this->staff);
        $this->get(RegistroJornada::getUrl())->assertRedirect();
    }

    // --- 19–21. Retention and cross-checks ------------------------------------------------------------------------------

    public function test_a_soft_deleted_users_hours_remain_with_their_name_and_force_delete_is_blocked(): void
    {
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $name = $this->staff->name;
        $this->staff->delete();

        $this->actingAs($this->owner);
        $rows = Livewire::test(RegistroJornada::class)->invade()->reportRows();
        $this->assertSame($name, $rows[0]['name']);

        $this->expectException(RuntimeException::class);
        $this->staff->forceDelete();
    }

    public function test_the_audit_retention_sweep_leaves_clock_events_alone(): void
    {
        $this->travelTo($this->at('2016-01-01 10:00'));
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelBack();

        Artisan::call('audit:redact-retention');

        $this->assertSame(1, StaffClockEvent::query()->count());
        $this->assertNotNull(StaffClockEvent::query()->sole()->occurred_at);
    }

    public function test_unclocked_activity_is_flagged_and_the_report_is_bounded(): void
    {
        foreach ([$this->staff, $this->manager] as $person) {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::ulid(), 'organisation_id' => $this->org->id, 'actor_id' => $person->id,
                'action' => 'counter.operator.signed_in', 'auditable_type' => $this->centro->getMorphClass(), 'auditable_id' => $this->centro->id,
                'after' => json_encode(['location_id' => $this->centro->id]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->owner);
        $page = Livewire::test(RegistroJornada::class)->invade(); // reportRows is protected (post-296 P3-3)
        $count = function () use ($page): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->reportRows();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $flags = collect($page->reportRows())->pluck('flags')->flatten()->all();
        $this->assertContains(__('Actividad sin fichar'), $flags);

        // One period first, so both measurements take the same code path (eager loads fire once there is data).
        $this->travelTo($this->at('2026-09-10 10:00'));
        (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
        $this->travelTo($this->at('2026-09-10 12:00'));
        (new ClockOut)->handle($this->staff, $this->staff, StaffClockSource::PIN);
        $this->travelTo($this->at('2026-09-26 18:00'));
        $before = $count();

        foreach (range(1, 6) as $i) {
            $this->travelTo($this->at('2026-09-'.(10 + $i).' 10:00'));
            (new ClockIn)->handle($this->staff, $this->centro, $this->staff);
            $this->travelTo($this->at('2026-09-'.(10 + $i).' 12:00'));
            (new ClockOut)->handle($this->staff, $this->staff, StaffClockSource::PIN);
        }
        $this->travelTo($this->at('2026-09-26 18:00'));
        $this->assertSame($before, $count(), 'the report runs queries per row');
    }

    // --- 22. RAT and roles page ---------------------------------------------------------------------------------------

    public function test_the_rat_lists_the_activity_and_the_roles_page_the_permissions(): void
    {
        $this->assertContains(__('Registro de jornada del personal'), array_column((new Rat)->activities(), 'name'));

        $listed = array_merge(...array_values(Permissions::groups()));
        foreach (['staff.hours.view', 'staff.hours.manage'] as $permission) {
            $this->assertContains($permission, $listed);
            $this->assertNotSame($permission, Permissions::label($permission));
            $this->assertTrue($this->manager->can($permission));
            $this->assertFalse($this->staff->can($permission));
        }
    }
}
