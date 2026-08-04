<?php

namespace Tests\Feature\Governance;

use App\Actions\Governance\DraftAssemblyMinute;
use App\Actions\Governance\IssueConvocatoria;
use App\Actions\Governance\RecordAttendance;
use App\Actions\Governance\RecordResolution;
use App\Actions\Members\AnonymiseMember;
use App\Enums\AttendanceMode;
use App\Enums\MinuteBook;
use App\Enums\ResolutionResult;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Models\AssemblyAttendance;
use App\Models\Convocatoria;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\AssemblyQuorum;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 137 — running an assembly end to end: attendance (present/proxy) against the frozen roll, live
 * quorum, per-item resolutions, and drafting the acta FROM what was recorded (reusing CreateMinute). Every
 * write goes through a governance Action gated on minutes.manage.
 */
class AssemblyTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Mail::fake();
    }

    /**
     * An issued convocatoria with a frozen roll of $count members. Built through the real writers so the roll
     * and quorum are exactly what production produces.
     *
     * @return array{0: Convocatoria, 1: Collection<int, Member>}
     */
    private function issued(int $count): array
    {
        $members = Member::factory()->count($count)->create([
            'organisation_id' => $this->org->id,
            'joined_at' => '2026-01-01',
            'email' => null,
        ]);

        $convocatoria = Convocatoria::factory()->create([
            'organisation_id' => $this->org->id,
            'held_at' => now()->addDays(30),
            'agenda' => ['Aprobación de cuentas', 'Ruegos y preguntas'],
        ]);

        return [(new IssueConvocatoria)->handle($convocatoria, $this->owner), $members];
    }

    public function test_present_and_proxy_both_count_toward_the_quorum(): void
    {
        [$convocatoria, $members] = $this->issued(4); // quorum_required = ceil(4 * 50%) = 2

        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $this->owner);
        (new RecordAttendance)->handle($convocatoria, $members[1], AttendanceMode::PROXY, 'Junta directiva', $this->owner);

        $quorum = AssemblyQuorum::forConvocatoria($convocatoria->refresh());

        $this->assertSame(4, $quorum->roll);
        $this->assertSame(2, $quorum->present);           // present + proxy both counted
        $this->assertSame(2, $quorum->firstCallRequired);
        $this->assertTrue($quorum->firstCallMet());
        $this->assertTrue($quorum->isConstituted());
    }

    public function test_a_member_not_on_the_roll_cannot_be_marked_present(): void
    {
        [$convocatoria] = $this->issued(2);
        $stranger = Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => '2026-08-01']);

        $this->expectException(RuntimeException::class);
        (new RecordAttendance)->handle($convocatoria, $stranger, AttendanceMode::PRESENT, null, $this->owner);
    }

    public function test_a_proxy_attendance_must_name_the_holder(): void
    {
        [$convocatoria, $members] = $this->issued(2);

        $this->expectException(RuntimeException::class);
        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PROXY, '  ', $this->owner);
    }

    public function test_re_marking_a_member_updates_the_same_row_never_duplicates(): void
    {
        [$convocatoria, $members] = $this->issued(2);

        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $this->owner);
        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PROXY, 'Tesorero', $this->owner);

        $rows = AssemblyAttendance::where('convocatoria_id', $convocatoria->id)->where('member_id', $members[0]->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(AttendanceMode::PROXY, $rows->first()?->mode);
        $this->assertSame('Tesorero', $rows->first()?->proxy_holder);
    }

    public function test_removing_an_attendance_mark_lowers_the_count(): void
    {
        [$convocatoria, $members] = $this->issued(2);
        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $this->owner);

        (new RecordAttendance)->remove($convocatoria, $members[0], $this->owner);

        $this->assertSame(0, AssemblyQuorum::forConvocatoria($convocatoria)->present);
    }

    public function test_the_second_call_quorum_comes_from_settings(): void
    {
        Settings::set('assembly_second_call_quorum_bp', 2500, SettingType::BP); // 25%
        [$convocatoria] = $this->issued(8); // first-call = ceil(8*50%) = 4; second-call = ceil(8*25%) = 2

        $quorum = AssemblyQuorum::forConvocatoria($convocatoria);
        $this->assertSame(4, $quorum->firstCallRequired);
        $this->assertSame(2, $quorum->secondCallRequired);

        // Default 0 → constituted on second call whatever the attendance.
        Settings::set('assembly_second_call_quorum_bp', 0, SettingType::BP);
        $this->assertSame(0, AssemblyQuorum::forConvocatoria($convocatoria)->secondCallRequired);
    }

    public function test_recording_a_resolution_stores_the_outcome_and_votes_keyed_by_position(): void
    {
        [$convocatoria] = $this->issued(3);

        (new RecordResolution)->handle($convocatoria, 1, 'Aprobación de cuentas', ResolutionResult::APPROVED, 3, 1, 0, $this->owner);
        // Re-recording the same position corrects it, never duplicates.
        (new RecordResolution)->handle($convocatoria, 1, 'Aprobación de cuentas', ResolutionResult::REJECTED, 1, 4, 1, $this->owner);

        $resolutions = $convocatoria->resolutions()->get();
        $this->assertCount(1, $resolutions);
        $this->assertSame(ResolutionResult::REJECTED, $resolutions->first()?->result);
        $this->assertSame(4, $resolutions->first()?->votes_against);
    }

    public function test_drafting_the_acta_snapshots_attendance_and_resolutions_and_links_the_convocatoria(): void
    {
        [$convocatoria, $members] = $this->issued(3); // quorum = 2

        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $this->owner);
        (new RecordAttendance)->handle($convocatoria, $members[1], AttendanceMode::PRESENT, null, $this->owner);
        (new RecordResolution)->handle($convocatoria, 1, 'Aprobación de cuentas', ResolutionResult::APPROVED, 2, 0, 0, $this->owner);

        $minute = (new DraftAssemblyMinute)->handle($convocatoria, $this->owner);

        $this->assertSame(MinuteBook::ASSEMBLY, $minute->book);
        $this->assertNull($minute->signed_at);                       // created UNSIGNED
        $this->assertSame($convocatoria->id, $minute->convocatoria_id);
        $this->assertSame(2, $minute->quorum_present);               // two attendees snapshotted
        $this->assertCount(2, $minute->attendees);

        // The resolution outcome is captured in the acta's own JSON — with the locale-stable Spanish term.
        $this->assertSame('Aprobación de cuentas', $minute->resolutions[0]['texto']);
        $this->assertSame('Aprobado', $minute->resolutions[0]['resultado']);
        $this->assertSame(2, $minute->resolutions[0]['favor']);
    }

    public function test_a_second_draft_is_refused_while_one_is_unsigned(): void
    {
        [$convocatoria, $members] = $this->issued(2);
        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $this->owner);
        (new DraftAssemblyMinute)->handle($convocatoria, $this->owner);

        $this->expectException(RuntimeException::class);
        (new DraftAssemblyMinute)->handle($convocatoria, $this->owner);
    }

    public function test_the_acta_numbering_comes_from_create_minute(): void
    {
        [$c1, $m1] = $this->issued(2);
        (new RecordAttendance)->handle($c1, $m1[0], AttendanceMode::PRESENT, null, $this->owner);
        $first = (new DraftAssemblyMinute)->handle($c1, $this->owner);

        [$c2, $m2] = $this->issued(2);
        (new RecordAttendance)->handle($c2, $m2[0], AttendanceMode::PRESENT, null, $this->owner);
        $second = (new DraftAssemblyMinute)->handle($c2, $this->owner);

        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);                       // sequential per (org, book)
    }

    public function test_nothing_can_be_recorded_for_a_draft_convocatoria(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => '2026-01-01']);
        $draft = Convocatoria::factory()->create(['organisation_id' => $this->org->id, 'held_at' => now()->addDays(30)]);

        $this->expectException(RuntimeException::class);
        (new RecordAttendance)->handle($draft, $member, AttendanceMode::PRESENT, null, $this->owner);
    }

    public function test_a_staff_user_cannot_record_attendance(): void
    {
        [$convocatoria, $members] = $this->issued(2);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $this->assertFalse($staff->can('minutes.manage'));

        $this->expectException(AuthorizationException::class);
        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $staff);
    }

    public function test_a_staff_user_cannot_draft_the_acta(): void
    {
        [$convocatoria] = $this->issued(2);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        $this->expectException(AuthorizationException::class);
        (new DraftAssemblyMinute)->handle($convocatoria, $staff);
    }

    public function test_anonymising_a_member_redacts_the_attendance_name_but_keeps_the_row(): void
    {
        [$convocatoria, $members] = $this->issued(2);
        (new RecordAttendance)->handle($convocatoria, $members[0], AttendanceMode::PRESENT, null, $this->owner);

        (new AnonymiseMember)->handle($members[0]);

        $row = AssemblyAttendance::withoutGlobalScopes()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('member_id', $members[0]->id)
            ->first();

        $this->assertNotNull($row);                                  // evidence of attendance survives
        $this->assertSame('[borrado]', $row->name);                  // the personal snapshot is redacted
        $this->assertSame(AttendanceMode::PRESENT, $row->mode);      // the fact (present) is kept
    }
}
