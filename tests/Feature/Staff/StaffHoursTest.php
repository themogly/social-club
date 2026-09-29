<?php

namespace Tests\Feature\Staff;

use App\Actions\RecordAuditLog;
use App\Actions\Roles\SetRolePermission;
use App\Actions\Staff\AnnulClockEvent;
use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Enums\Role;
use App\Enums\StaffClockSource;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\StaffHoursReportPage;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Duration;
use App\Support\Period;
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\StaffHours;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 285 — staff hours at a glance: on the dashboard ("what's happening") and a report of their own ("how it went").
 *
 * Every figure is read through ONE view model (`StaffHours`) built from ONE `WorkedHours::periods()` call — so the
 * register, the dashboard and the report pair events the same way, and a past day's open period is counted as zero
 * (never an invented duration), exactly as 281 refuses to invent a clock-out.
 */
class StaffHoursTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

    private User $manager;

    private User $staff;

    private User $marta;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        Artisan::call('csc:sync-permissions');
        $this->travelTo($this->at('2026-09-26 18:00')); // a Saturday evening
        $this->org = Organisation::factory()->create(['legal_name' => 'Asociación Prueba']);
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00']);
        app(ActiveScope::class)->setLocation(null);

        $this->owner = $this->user(Role::OWNER, 'Olga Propietaria', [$this->centro, $this->norte]);
        $this->manager = $this->user(Role::MANAGER, 'Manu Responsable', [$this->centro]);
        $this->staff = $this->user(Role::STAFF, 'Sara Personal', [$this->centro]);
        $this->marta = $this->user(Role::STAFF, 'Marta Centro', [$this->centro]);
        $this->luis = $this->user(Role::STAFF, 'Luis Norte', [$this->norte]);
    }

    /** @param list<Location> $at */
    private function user(Role $role, string $name, array $at): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => Hash::make((string) random_int(100000, 999999)), 'active' => true]);
        $user->assignRole($role->value);
        $user->locations()->attach(array_map(fn (Location $l): string => $l->id, $at));

        return $user;
    }

    private function at(string $madrid): CarbonImmutable
    {
        return CarbonImmutable::parse($madrid, 'Europe/Madrid');
    }

    /** A shift through the real writers. `$declared` ends it as a typed (self-declared) time. */
    private function shift(User $who, Location $where, string $in, ?string $out, bool $declared = false): void
    {
        (new ClockIn)->handle($who, $where, $who, StaffClockSource::PIN, $this->at($in));
        if ($out !== null) {
            (new ClockOut)->handle($who, $who, $declared ? StaffClockSource::SELF_DECLARED : StaffClockSource::PIN, $this->at($out), $declared ? 'Me olvidé' : null);
        }
    }

    private function scope(?Location $location): void
    {
        app(ActiveScope::class)->setLocation($location?->id);
        session(['scope.location_id' => $location?->id]);
    }

    private function month(): Period
    {
        return Period::custom(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), $this->centro);
    }

    /** @return array<string, array<string, mixed>> per person, keyed by user id */
    private function people(User $viewer, Period $period): array
    {
        return collect(StaffHours::for($viewer, $period)->perPerson())->keyBy('user_id')->all();
    }

    // --- Counting rules ----------------------------------------------------------------------------------------------

    public function test_1_complete_counts_annulled_counts_nothing_declared_goes_to_its_own_bucket(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');           // 240 clocked
        $this->shift($this->marta, $this->centro, '2026-09-11 10:00', '2026-09-11 13:00');           // annulled below
        $this->shift($this->marta, $this->centro, '2026-09-12 10:00', '2026-09-12 12:00', true);     // 120 declared
        (new ClockIn)->handle($this->marta, $this->centro, $this->owner, StaffClockSource::MANAGER_CORRECTION, $this->at('2026-09-13 10:00'), 'Olvidó fichar');
        (new ClockOut)->handle($this->marta, $this->marta, StaffClockSource::PIN, $this->at('2026-09-13 11:00'));   // 60 corrected

        $annulTarget = StaffClockEvent::query()->withoutGlobalScopes()->where('user_id', $this->marta->id)->where('type', 'IN')
            ->whereDate('business_date', '2026-09-11')->firstOrFail();
        (new AnnulClockEvent)->handle($annulTarget, $this->owner, 'Duplicado');

        $marta = $this->people($this->owner, $this->month())[$this->marta->id];

        $this->assertSame(240, $marta['clocked_minutes']);
        $this->assertSame(180, $marta['declared_minutes']);
        $this->assertSame(420, $marta['total_minutes']);
        $this->assertSame(3, $marta['shifts']);
    }

    public function test_2_open_today_counts_to_now_and_an_open_past_day_counts_zero(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-25 17:00', null);   // yesterday, never closed
        $this->shift($this->luis, $this->norte, '2026-09-26 16:30', null);     // today, in progress

        $hours = StaffHours::for($this->owner, $this->month());
        $people = collect($hours->perPerson())->keyBy('user_id');

        $this->assertSame(90, $people[$this->luis->id]['total_minutes']);
        $this->assertSame(1, $people[$this->luis->id]['in_progress']);
        $this->assertSame(0, $people[$this->marta->id]['total_minutes']);
        $this->assertSame(1, $people[$this->marta->id]['open_past']);
        $this->assertSame(1, $hours->openShiftsCount());
        $this->assertSame([$this->luis->id], array_column($hours->now(), 'user_id'));
    }

    public function test_3_a_shift_past_midnight_belongs_to_the_evening_and_the_heatmap_splits_it(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-19 20:00', '2026-09-20 02:00'); // Saturday night

        $hours = StaffHours::for($this->owner, $this->month());
        $days = $hours->perDay();
        $sat = array_search('2026-09-19', $days['keys'], true);
        $sun = array_search('2026-09-20', $days['keys'], true);

        $this->assertSame(360, $days['series'][$this->marta->id][$sat]);
        $this->assertSame(0, $days['series'][$this->marta->id][$sun]);

        $matrix = $hours->coverage()['matrix'];
        $this->assertSame(240, array_sum(array_slice($matrix[6], 20, 4)));   // Sat 20–24 → 4 h
        $this->assertSame(120, array_sum(array_slice($matrix[0], 0, 2)));    // Sun 00–02 → 2 h
        $this->assertSame(360, array_sum(array_map('array_sum', $matrix)));
    }

    // --- Visibility --------------------------------------------------------------------------------------------------

    public function test_4_a_manager_sees_their_sedes_the_owner_all_and_the_top_bar_narrows_both(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');
        $this->shift($this->luis, $this->norte, '2026-09-10 10:00', '2026-09-10 12:00');

        $this->scope(null);
        $this->assertEqualsCanonicalizing([$this->marta->id, $this->luis->id], array_keys($this->people($this->owner, $this->month())));
        $this->assertSame([$this->marta->id], array_keys($this->people($this->manager, $this->month())));

        $this->scope($this->norte);
        $this->assertSame([$this->luis->id], array_keys($this->people($this->owner, $this->month())));
        $this->assertSame([], array_keys($this->people($this->manager, $this->month())), 'a manager narrowed to a sede that is not theirs sees nothing');
    }

    public function test_5_staff_see_none_of_it(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-25 17:00', null);
        $this->scope($this->centro);
        $this->giveStaffThePanel(); // a club that lets staff into the panel — so this tests the feature's own gate

        $html = $this->actingAs($this->staff)->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-staff-now', $html);
        $this->assertStringNotContainsString('data-staff-hours-chart', $html);
        $this->assertStringNotContainsString('data-alert="staff_open_shifts"', $html);
        $this->get(StaffHoursReportPage::getUrl())->assertForbidden();

        // Even granted the permission, STAFF never see hours on the dashboard — their own are in "Mis horas".
        $this->setRolePermission(Role::STAFF, 'staff.hours.view', true);
        $this->get('/')->assertOk()->assertDontSee('data-staff-now', false)->assertDontSee('data-staff-hours-chart', false);
    }

    public function test_6_revoking_the_permission_on_the_roles_page_hides_it_without_logging_out(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-26 16:00', null);
        $this->scope($this->centro);
        $this->actingAs($this->manager);

        $this->get('/')->assertOk()->assertSee('data-staff-now', false);

        (new SetRolePermission)->handle(Role::MANAGER, 'staff.hours.view', false, $this->owner);

        $this->get('/')->assertOk()->assertDontSee('data-staff-now', false)->assertDontSee('data-staff-hours-chart', false);
        $this->get(StaffHoursReportPage::getUrl())->assertForbidden();
    }

    // --- Dashboard ---------------------------------------------------------------------------------------------------

    public function test_7_personal_ahora_lists_exactly_the_open_periods_today_whatever_the_period(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-26 10:00', '2026-09-26 14:00');   // done for the day
        $this->shift($this->luis, $this->norte, '2026-09-26 16:30', null);                  // in now
        $this->shift($this->manager, $this->centro, '2026-09-24 17:00', null);              // open from a past day
        $this->scope(null);
        $this->actingAs($this->owner);

        foreach (['today', 'month', 'week'] as $period) {
            $html = Livewire::test(Dashboard::class)->set('period', $period)->html();
            $this->assertSame(1, substr_count($html, 'data-staff-now-row'), "period {$period}");
            $this->assertStringContainsString('Luis Norte', $html);
        }
    }

    public function test_8_the_hours_alerts_use_31_days_not_the_dashboard_period(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-20 17:00', null);   // open since the 20th
        // Luis signed in at the counter on the 21st and never clocked in.
        $this->travelTo($this->at('2026-09-21 20:00'));
        $this->actingAs($this->luis);
        (new RecordAuditLog)->handle('counter.operator.signed_in', $this->centro);
        $this->travelTo($this->at('2026-09-26 18:00'));

        $this->scope(null);
        $this->actingAs($this->owner);

        Livewire::test(Dashboard::class)->set('period', 'today')
            ->assertSee('data-alert="staff_open_shifts"', false)
            ->assertSee('data-alert="staff_unclocked_activity"', false);
    }

    // --- Report page -------------------------------------------------------------------------------------------------

    public function test_9_the_stat_cards_match_a_hand_computed_fixture(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-03 10:00', '2026-09-03 16:00');           // previous week: 360
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');           // 240
        $this->shift($this->luis, $this->norte, '2026-09-10 10:00', '2026-09-10 12:00', true);       // 120 declared
        $this->shift($this->marta, $this->centro, '2026-09-12 10:00', '2026-09-12 13:00');           // 180
        $this->scope(null);

        $period = Period::custom(CarbonImmutable::parse('2026-09-08'), CarbonImmutable::parse('2026-09-14'), $this->centro);
        $summary = StaffHours::for($this->owner, $period)->summary();

        $this->assertSame(540, $summary['total_minutes']);
        $this->assertSame(360, $summary['previous_total_minutes']);
        $this->assertSame(2, $summary['people']);
        $this->assertSame(180, $summary['average_shift_minutes']);
        $this->assertSame(22, $summary['declared_percent']);   // 120 / 540

        $this->actingAs($this->owner);
        Livewire::test(StaffHoursReportPage::class)
            ->set('period', 'custom')->set('customStart', '2026-09-08')->set('customEnd', '2026-09-14')
            ->assertSee('9 h')
            ->assertSee('+50%')
            ->assertSee('3 h 00 min')
            ->assertSee('22 %');
    }

    public function test_10_hours_per_day_groups_by_week_past_62_days(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');
        $this->scope(null);

        $short = StaffHours::for($this->owner, $this->month())->perDay();
        $this->assertFalse($short['by_week']);
        $this->assertCount(30, $short['keys']);

        $long = StaffHours::for($this->owner, Period::custom(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-09-26'), $this->centro))->perDay();
        $this->assertTrue($long['by_week']);
        $this->assertLessThanOrEqual(18, count($long['keys']));
        $this->assertSame(240, array_sum($long['series'][$this->marta->id]));
    }

    public function test_11_the_csv_matches_the_table(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');
        $this->shift($this->luis, $this->norte, '2026-09-11 10:00', '2026-09-11 12:30', true);
        $this->scope(null);
        $this->actingAs($this->owner);

        $page = new StaffHoursReportPage;
        $page->mount();
        $page->period = 'month';

        ob_start();
        $response = $page->exportCsv();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $table = StaffHours::for($this->owner, $page->resolvePeriod(), array_values(array_filter([$this->centro->id, $this->norte->id])))->table();
        $this->assertSame(ReportExport::csv($table->sortedBy(null)), $csv);
        $this->assertStringContainsString('Marta Centro', $csv);
        $this->assertStringContainsString('Luis Norte', $csv);
    }

    // --- Performance, names, formatting -----------------------------------------------------------------------------

    public function test_12_the_dashboard_is_bounded_and_pairs_events_once_per_request(): void
    {
        $this->scope(null);
        $this->actingAs($this->owner);
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');
        $this->get('/')->assertOk(); // warm: settings, permissions

        $measure = function (): array {
            $queries = [];
            DB::listen(function ($q) use (&$queries): void {
                $queries[] = $q->sql;
            });
            $this->get('/')->assertOk();
            DB::flushQueryLog();

            return $queries;
        };

        $small = $measure();

        foreach (range(1, 6) as $i) {
            $person = $this->user(Role::STAFF, "Persona {$i}", [$this->centro]);
            $this->shift($person, $this->centro, "2026-09-1{$i} 10:00", "2026-09-1{$i} 14:00");
            $this->shift($person, $this->centro, '2026-09-26 1'.$i.':00', null);
        }

        $big = $measure();
        $pairingPasses = count(array_filter($big, fn (string $sql): bool => (bool) preg_match('/staff_clock_events.*occurred_at.? >=/', $sql)));

        $this->assertSame(1, $pairingPasses, 'WorkedHours::periods() must run once per dashboard request');
        $this->assertLessThanOrEqual(count($small) + 2, count($big), 'the dashboard\'s query count grows with the number of people');
    }

    public function test_13_a_soft_deleted_persons_hours_remain_marked_as_left(): void
    {
        $this->shift($this->marta, $this->centro, '2026-09-10 10:00', '2026-09-10 14:00');
        $this->marta->delete();
        $this->scope(null);

        $marta = $this->people($this->owner, $this->month())[$this->marta->id];
        $this->assertSame('Marta Centro', $marta['name']);
        $this->assertTrue($marta['left']);
        $this->assertSame(240, $marta['total_minutes']);

        $this->actingAs($this->owner);
        Livewire::test(StaffHoursReportPage::class)->assertSee(__(':name (ya no está)', ['name' => 'Marta Centro']));
    }

    public function test_14_durations_and_totals_render_in_spanish(): void
    {
        app()->setLocale('es');
        $this->assertSame('7 h 30 min', Duration::format(450));
        $this->assertSame('0 h 05 min', Duration::format(5));
        $this->assertSame('152.5 h', Duration::hours(9150));
        $this->assertSame('8 h', Duration::hours(480));
    }
}
