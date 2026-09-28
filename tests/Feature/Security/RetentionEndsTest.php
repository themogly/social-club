<?php

namespace Tests\Feature\Security;

use App\Actions\Members\AnonymiseMember;
use App\Actions\Staff\AnnulClockEvent;
use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StaffClockSource;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\ViewModels\Rat;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post-296 audit, Phase 2 — two copies of personal data that nothing ever removed.
 *
 * (A·5) A mail that failed to send sits in `failed_jobs` with its payload: a receipt's grams (Article 9), a name, an
 *       address, a card token. Nothing pruned the table and erasure did not reach it.
 * (B·P2-1) The registro de jornada said "4 años como mínimo" and kept every event forever.
 */
class RetentionEndsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Artisan::call('csc:sync-permissions');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'timezone' => 'Europe/Madrid']);
        $this->owner = User::factory()->create(['active' => true]);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    private function failedJob(string $payload): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default',
            'payload' => $payload, 'exception' => 'x', 'failed_at' => now()]);

        return $uuid;
    }

    // --- failed_jobs ------------------------------------------------------------------------------------------------

    public function test_failed_jobs_are_pruned_on_a_schedule(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

        $this->assertStringContainsString('queue:prune-failed', $commands);
        $this->assertStringContainsString('--hours=168', $commands);
        $this->assertStringContainsString('staff:prune-clock-events', $commands);
    }

    public function test_erasing_a_member_removes_the_failed_jobs_that_name_them(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'ainhoa@example.test']);
        $byId = $this->failedJob('{"data":{"command":"O:8:\"Receipt\":1:{s:2:\"id\";s:26:\"'.$member->id.'\";}"}}');
        $byEmail = $this->failedJob('{"data":{"command":"...ainhoa@example.test..."}}');
        $someoneElse = $this->failedJob('{"data":{"command":"...otra@example.test..."}}');

        (new AnonymiseMember)->handle($member);

        $this->assertSame([$someoneElse], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertNotContains($byId, DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertNotContains($byEmail, DB::table('failed_jobs')->pluck('uuid')->all());
    }

    public function test_the_rat_declares_the_failed_mail_retention(): void
    {
        $this->assertStringContainsString(__('Un correo que no se pudo entregar se conserva en la cola de fallidos como máximo :days días y un barrido programado lo elimina; la supresión del socio también lo borra.', ['days' => 7]),
            collect((new Rat)->activities())->firstWhere('ref', 'RAT-01')['retention']);
    }

    // --- the registro de jornada -------------------------------------------------------------------------------------

    public function test_clock_events_past_the_retention_are_deleted_and_the_run_is_audited(): void
    {
        $this->travelTo(CarbonImmutable::parse('2020-03-10 10:00', 'Europe/Madrid'));
        $old = (new ClockIn)->handle($this->owner, $this->sede, $this->owner, StaffClockSource::PIN);
        $this->travel(1)->hour();
        $oldAnnulled = (new ClockOut)->handle($this->owner, $this->owner, StaffClockSource::PIN);
        (new AnnulClockEvent)->handle($oldAnnulled, $this->owner, 'Duplicado');
        $this->travelBack();
        $colleague = User::factory()->create(['active' => true]);
        $colleague->assignRole(Role::STAFF->value);
        $colleague->locations()->sync([$this->sede->id]);
        $recent = (new ClockIn)->handle($colleague, $this->sede, $colleague, StaffClockSource::PIN);

        Artisan::call('staff:prune-clock-events');

        $this->assertSame([$recent->id], StaffClockEvent::query()->withoutGlobalScopes()->pluck('id')->all());
        $this->assertNull(StaffClockEvent::query()->withoutGlobalScopes()->find($old->id));
        $audit = AuditLog::query()->where('action', 'staff.clock.retention.pruned')->sole();
        $this->assertSame(3, $audit->after['deleted']);

        Artisan::call('staff:prune-clock-events'); // idempotent
        $this->assertSame(1, AuditLog::query()->where('action', 'staff.clock.retention.pruned')->count());
    }

    public function test_the_legal_minimum_of_four_years_is_never_cut(): void
    {
        Settings::set('staff_clock_retention_years', 1, SettingType::INT);
        $this->travelTo(now()->subYears(3));
        $threeYearsOld = (new ClockIn)->handle($this->owner, $this->sede, $this->owner, StaffClockSource::PIN);
        $this->travelBack();

        Artisan::call('staff:prune-clock-events');

        $this->assertNotNull(StaffClockEvent::query()->withoutGlobalScopes()->find($threeYearsOld->id));
    }

    public function test_the_append_only_model_still_refuses_a_delete(): void
    {
        $event = (new ClockIn)->handle($this->owner, $this->sede, $this->owner, StaffClockSource::PIN);

        $this->expectException(\RuntimeException::class);
        $event->delete();
    }

    public function test_rat_08_states_when_the_registro_is_deleted(): void
    {
        $this->assertStringContainsString('5', collect((new Rat)->activities())->firstWhere('ref', 'RAT-08')['retention']);
        $this->assertStringNotContainsString(__('4 años como mínimo, también tras la baja de la persona.'), collect((new Rat)->activities())->firstWhere('ref', 'RAT-08')['retention']);
    }
}
