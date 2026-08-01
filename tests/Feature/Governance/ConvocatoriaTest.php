<?php

namespace Tests\Feature\Governance;

use App\Actions\Documents\CreateMinute;
use App\Actions\Governance\IssueConvocatoria;
use App\Enums\ConvocatoriaRecipientStatus;
use App\Enums\ConvocatoriaType;
use App\Enums\MinuteBook;
use App\Enums\Role;
use App\Mail\ConvocatoriaMail;
use App\Models\Convocatoria;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 88 — convening a general assembly and emailing the membership. The load-bearing guarantees:
 * the recipient roll is FROZEN at issue (members as-at the notice date, stored, never recomputed), each
 * member gets ONE separate email (never a shared To/CC that would leak addresses), members with no email
 * are recorded as un-notified rather than silently dropped, the legal notice period is enforced, and the
 * acta can reference the convocatoria that called the meeting.
 */
class ConvocatoriaTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /** @param array<string, mixed> $attrs */
    private function member(array $attrs = []): Member
    {
        return Member::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'joined_at' => '2026-01-01',
            'left_at' => null,
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function convocatoria(array $attrs = []): Convocatoria
    {
        return Convocatoria::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'type' => ConvocatoriaType::ORDINARY,
            'held_at' => now()->addDays(30),
        ], $attrs));
    }

    public function test_issuing_freezes_the_roll_as_at_the_notice_date_and_never_recomputes_it(): void
    {
        Mail::fake();
        $this->member(['member_no' => 'M-001', 'email' => 'a@club.example']);
        $this->member(['member_no' => 'M-002', 'email' => 'b@club.example']);
        $this->member(['member_no' => 'M-003', 'joined_at' => '2026-09-01']);           // joins AFTER the notice — excluded
        $this->member(['member_no' => 'M-004', 'left_at' => '2026-05-01']);             // left BEFORE the notice — excluded

        $convocatoria = $this->convocatoria();
        $issued = (new IssueConvocatoria)->handle($convocatoria, $this->user(Role::OWNER));

        $this->assertSame(2, $issued->roll_count);
        $this->assertEqualsCanonicalizing(
            ['M-001', 'M-002'],
            $issued->recipients()->pluck('member_no')->all(),
        );

        // Freeze proof: a member who joins AFTER issue is not added — the stored roll is never recomputed.
        $this->member(['member_no' => 'M-100', 'email' => 'late@club.example']);
        $this->assertSame(2, $issued->fresh()?->recipients()->count());
    }

    public function test_it_sends_one_separate_email_per_member_never_a_shared_recipient_list(): void
    {
        Mail::fake();
        $this->member(['email' => 'a@club.example']);
        $this->member(['email' => 'b@club.example']);
        $this->member(['email' => 'c@club.example']);

        (new IssueConvocatoria)->handle($this->convocatoria(), $this->user(Role::OWNER));

        // Exactly one message per member…
        Mail::assertQueued(ConvocatoriaMail::class, 3);
        // …and each is addressed to exactly one recipient (never a shared To/CC leaking the whole roll).
        foreach (['a@club.example', 'b@club.example', 'c@club.example'] as $email) {
            Mail::assertQueued(ConvocatoriaMail::class, fn (ConvocatoriaMail $mail): bool => $mail->hasTo($email) && count($mail->to) === 1);
        }
    }

    public function test_a_member_without_an_email_is_recorded_on_the_roll_as_un_notified(): void
    {
        Mail::fake();
        $withEmail = $this->member(['member_no' => 'M-001', 'email' => 'a@club.example']);
        $noEmail = $this->member(['member_no' => 'M-002', 'email' => null]);

        $issued = (new IssueConvocatoria)->handle($this->convocatoria(), $this->user(Role::OWNER));

        // Present on the roll — never silently dropped — but flagged NO_EMAIL and un-notified.
        $row = $issued->recipients()->where('member_id', $noEmail->id)->firstOrFail();
        $this->assertSame(ConvocatoriaRecipientStatus::NO_EMAIL, $row->status);
        $this->assertNull($row->notified_at);

        $notified = $issued->recipients()->where('member_id', $withEmail->id)->firstOrFail();
        $this->assertSame(ConvocatoriaRecipientStatus::NOTIFIED, $notified->status);
        $this->assertNotNull($notified->notified_at);

        // Only the member with an email is emailed.
        Mail::assertQueued(ConvocatoriaMail::class, 1);
    }

    public function test_the_notice_period_is_enforced_and_a_too_soon_assembly_is_refused(): void
    {
        Mail::fake();
        $this->member(['email' => 'a@club.example']);
        // Default assembly_notice_days = 15; a meeting in 5 days is too soon.
        $convocatoria = $this->convocatoria(['held_at' => now()->addDays(5)]);

        try {
            (new IssueConvocatoria)->handle($convocatoria, $this->user(Role::OWNER));
            $this->fail('An assembly inside the notice period should be refused.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull($convocatoria->fresh()?->issued_at);
        $this->assertSame(0, $convocatoria->fresh()?->recipients()->count());
        Mail::assertNothingQueued();
    }

    public function test_the_quorum_and_notice_period_are_frozen_onto_the_convocatoria(): void
    {
        Mail::fake();
        // 4 members active as-at the notice date; default quorum fraction 50% → 2 required.
        for ($i = 1; $i <= 4; $i++) {
            $this->member(['member_no' => "M-00{$i}", 'email' => "m{$i}@club.example"]);
        }

        $issued = (new IssueConvocatoria)->handle($this->convocatoria(), $this->user(Role::OWNER));

        $this->assertSame(4, $issued->roll_count);
        $this->assertSame(2, $issued->quorum_required); // ceil(4 × 0.5)
        $this->assertSame(15, $issued->notice_days);
    }

    public function test_a_convocatoria_cannot_be_issued_twice(): void
    {
        Mail::fake();
        $this->member(['email' => 'a@club.example']);
        $convocatoria = $this->convocatoria();
        (new IssueConvocatoria)->handle($convocatoria, $this->user(Role::OWNER));

        $this->expectException(RuntimeException::class);
        (new IssueConvocatoria)->handle($convocatoria->fresh(), $this->user(Role::OWNER));
    }

    public function test_an_issued_convocatoria_is_immutable(): void
    {
        Mail::fake();
        $this->member(['email' => 'a@club.example']);
        $convocatoria = $this->convocatoria();
        (new IssueConvocatoria)->handle($convocatoria, $this->user(Role::OWNER));

        $this->expectException(RuntimeException::class);
        $convocatoria->fresh()->update(['title' => 'Cambiado']);
    }

    public function test_issuing_requires_the_minutes_manage_permission(): void
    {
        Mail::fake();
        $this->member(['email' => 'a@club.example']);
        $staff = $this->user(Role::STAFF);
        $this->assertFalse($staff->can('minutes.manage'));

        $convocatoria = $this->convocatoria();
        try {
            (new IssueConvocatoria)->handle($convocatoria, $staff);
            $this->fail('A staff member without minutes.manage must not convene an assembly.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertNull($convocatoria->fresh()?->issued_at);
        Mail::assertNothingQueued();
    }

    public function test_an_acta_can_reference_the_convocatoria_that_called_the_meeting(): void
    {
        Mail::fake();
        $owner = $this->user(Role::OWNER);
        $convocatoria = $this->convocatoria();
        (new IssueConvocatoria)->handle($convocatoria, $owner);

        $minute = (new CreateMinute)->handle($this->org, MinuteBook::ASSEMBLY, [
            'held_on' => '2026-08-14',
            'convocatoria_id' => $convocatoria->id,
        ], $owner);

        $this->assertSame($convocatoria->id, $minute->convocatoria_id);
        $this->assertTrue($minute->convocatoria->is($convocatoria));
        $this->assertTrue($convocatoria->minutes()->whereKey($minute->id)->exists());
    }
}
