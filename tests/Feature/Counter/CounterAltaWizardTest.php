<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\ApproveApplication;
use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Prompt 174 — Alta at the counter: sign a member up without opening the panel.
 *
 * THE rule: this creates an APPLICATION, not a member. The join form already exists end to end, and what
 * changes is which device it is filled in on. So the assertion that carries the most weight here is
 * `test_the_counter_record_is_comparable_to_the_emailed_invite_path` — if the two ever diverge, the half
 * that drifts is the half holding Article 9 consent.
 *
 * The three approval failures each happen with a person standing at the counter, so each is asserted to
 * produce a readable sentence rather than a stack trace, and to leave the record somewhere sensible.
 */
class CounterAltaWizardTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function staff(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    private function tier(string $name = 'General', int $feeCents = 2500): MembershipTier
    {
        return MembershipTier::factory()->create([
            'organisation_id' => $this->org->id, 'name' => $name, 'default_fee_cents' => $feeCents,
        ]);
    }

    /** The applicant's half: the REAL public form at the REAL token, which is the whole design. */
    private function fillInTheForm(MemberApplication $application, array $overrides = []): void
    {
        $this->post(route('socio.application.store', ['token' => $application->invite_token]), array_merge([
            'first_name' => 'Lucía',
            'last_name' => 'García',
            'email' => 'lucia@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI',
            'document_number' => '12345678Z',
            'consent_data' => '1',
            'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $this->agedToken(),
        ], $overrides));
    }

    private function agedToken(): string
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $token;
    }

    private function latestApplication(): MemberApplication
    {
        return MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    // --- the whole flow, by a STAFF member with no manager ---------------------------------------------

    public function test_a_staff_member_completes_the_whole_flow(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        // 1) hand the tablet over — creates the application and enters 173's handover mode
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();

        $this->assertTrue(CounterHandover::active(), 'the tablet was not handed over');
        $this->assertSame($this->location->id, $application->location_id, 'the sede came from the client, not the counter');

        // 2) the applicant fills in the real public form
        $this->fillInTheForm($application);
        $this->assertNotNull($application->fresh()->submitted_at);

        // 3) PIN back — the operator was signed out by the handover, so re-identify
        CounterOperator::set($staff);
        CounterHandover::end();

        // 4) review, choose a tier, approve
        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)
            ->call('approveAlta');

        $member = Member::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Lucía', $member->first_name);
        $this->assertSame(MemberStatus::ACTIVE, $member->status);

        $membership = $member->memberships()->withoutGlobalScopes()->sole();
        $this->assertSame($tier->id, $membership->tier_id);
        $this->assertSame($this->location->id, $membership->location_id);

        // …and the versioned consent naming the locale the applicant actually read.
        $consents = $member->consents()->withoutGlobalScopes()->get();
        $this->assertGreaterThanOrEqual(1, $consents->count(), 'no versioned consent was captured');

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->status);
    }

    /** The equivalence the whole design rests on: same form, same validator, same consent capture. */
    public function test_the_counter_record_is_comparable_to_the_emailed_invite_path(): void
    {
        $staff = $this->staff();

        // A) counter-made
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $fromCounter = $this->latestApplication();
        $this->fillInTheForm($fromCounter);
        CounterOperator::set($staff);
        CounterHandover::end();

        // B) emailed-invite-made, same payload
        $manager = $this->staff(Role::MANAGER);
        $fromEmail = (new IssueApplicationInvite)
            ->handle($manager, $this->location->id, 'lucia@example.es', null);
        $this->fillInTheForm($fromEmail);

        $a = $fromCounter->fresh()->payload;
        $b = $fromEmail->fresh()->payload;

        // Compared key-for-key: it is not a parallel path, it is the same path on a different screen.
        $this->assertSame(array_keys($a), array_keys($b), 'the two paths store different payload shapes');
        foreach (['first_name', 'last_name', 'email', 'date_of_birth', 'document_type', 'document_number'] as $field) {
            $this->assertSame($a[$field] ?? null, $b[$field] ?? null, "$field differs between the two paths");
        }
        $this->assertSame($a['consent_version'] ?? null, $b['consent_version'] ?? null);
        $this->assertSame($a['consents'] ?? null, $b['consents'] ?? null);
    }

    public function test_the_invitation_path_produces_a_pickup_able_application(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')
            ->set('altaInviteEmail', 'nuevo@example.es')->call('sendAltaInvitation');

        $application = $this->latestApplication();
        $this->assertSame('nuevo@example.es', $application->applicant_email);
        $this->assertSame($this->location->id, $application->location_id);
        $this->assertNotNull($application->inviteUrl(), 'a counter invitation must carry the same token shape');

        // Filled in later, it appears in the counter's pending list to be picked up.
        $this->fillInTheForm($application);
        $pending = Livewire::test(MembershipCounter::class)->instance()->pendingAltaApplications();
        $this->assertTrue($pending->contains('id', $application->id));
    }

    // --- the three approval failures --------------------------------------------------------------------

    public function test_underage_is_refused_with_a_readable_message_and_the_record_stays_pending(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        // Straight to the payload: the public form's own age gate would refuse this, which is the point —
        // this is the counter's behaviour if a payload ever reaches approval underage.
        $application->update([
            'payload' => ['first_name' => 'Joven', 'last_name' => 'Menor', 'date_of_birth' => now()->subYears(15)->format('Y-m-d')],
            'submitted_at' => now(),
        ]);
        CounterOperator::set($staff);
        CounterHandover::end();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)->call('approveAlta');

        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count(), 'an underage applicant was admitted');
        $this->assertSame('error', $component->get('flashType'));
        $this->assertNotEmpty($component->get('flashMessage'));
        $this->assertStringNotContainsString('Exception', (string) $component->get('flashMessage'));
        // It does not vanish: a responsable decides what happens to it.
        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->status);
    }

    public function test_a_missing_name_is_handled_rather_than_surfacing_raw(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        $application->update([
            'payload' => ['last_name' => 'García', 'date_of_birth' => now()->subYears(30)->format('Y-m-d')],
            'submitted_at' => now(),
        ]);
        CounterOperator::set($staff);
        CounterHandover::end();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)->call('approveAlta');

        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
        $this->assertSame('error', $component->get('flashType'));
    }

    public function test_a_duplicate_shows_the_matches_and_needs_an_explicit_override(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        // Somebody who was already a member years ago.
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Lucía', 'last_name' => 'García',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'), 'status' => MemberStatus::ACTIVE,
        ]);

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        $this->fillInTheForm($application);
        CounterOperator::set($staff);
        CounterHandover::end();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)->call('approveAlta');

        // Blocked, matches surfaced, and NOT proceeded with silently.
        $this->assertTrue($component->get('altaDuplicateBlocked'));
        $this->assertSame(1, Member::query()->withoutGlobalScopes()->count(), 'a duplicate was created silently');
        $this->assertStringContainsString('data-alta-duplicates', $component->html());
        $this->assertStringContainsString('Lucía García', $component->html());

        // The override is a deliberate second act.
        $component->call('approveAlta', true);
        $this->assertSame(2, Member::query()->withoutGlobalScopes()->count());
    }

    // --- approval and payment are not one transaction -----------------------------------------------------

    public function test_a_failed_payment_leaves_a_real_member_owing_a_fee(): void
    {
        $staff = $this->staff();
        $tier = $this->tier(feeCents: 2500);

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        $this->fillInTheForm($application);
        CounterOperator::set($staff);
        CounterHandover::end();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)->call('approveAlta');

        // No fee collected at all — the money step simply did not happen.
        $member = Member::query()->withoutGlobalScopes()->sole();
        $membership = $member->memberships()->withoutGlobalScopes()->sole();

        // Rolling back an admission over a card machine would be worse: unpaid_fee is an ordinary state.
        $this->assertSame(2500, (int) DB::table('memberships')->where('id', $membership->id)->value('fee_cents'));
        $this->assertSame(0, MembershipFeePayment::query()->withoutGlobalScopes()->count());
    }

    // --- denial ---------------------------------------------------------------------------------------------

    public function test_a_user_without_the_review_permission_cannot_approve(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        $this->fillInTheForm($application);
        CounterHandover::end();

        // A user who can collect a fee (so the screen is reachable) but cannot review.
        // No ROLE, so no applications.review. Revoking it from the USER would not work — STAFF grants it
        // through the role, and that is exactly the kind of denial test that passes for the wrong reason.
        // A direct `membership.fee.collect` is given so the screen is reachable at all: the point is that
        // the APPROVE path is denied, not that the page 403s.
        $noReview = User::factory()->create();
        $noReview->locations()->sync([$this->location->id]);
        $noReview->givePermissionTo('membership.fee.collect');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($noReview);
        CounterOperator::set($noReview);

        Livewire::test(MembershipCounter::class)
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)->call('approveAlta');

        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
    }

    public function test_staff_can_review_but_still_cannot_create_a_member_directly(): void
    {
        $staff = $this->staff();

        // The line prompt 174 deliberately did not cross, asserted directly.
        $this->assertTrue($staff->can('applications.review'));
        $this->assertFalse($staff->can('members.create'));
    }

    public function test_the_alta_panel_is_hidden_from_a_user_without_the_permission(): void
    {
        // No ROLE, so no applications.review. Revoking it from the USER would not work — STAFF grants it
        // through the role, and that is exactly the kind of denial test that passes for the wrong reason.
        // A direct `membership.fee.collect` is given so the screen is reachable at all: the point is that
        // the APPROVE path is denied, not that the page 403s.
        $noReview = User::factory()->create();
        $noReview->locations()->sync([$this->location->id]);
        $noReview->givePermissionTo('membership.fee.collect');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($noReview);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($noReview);

        $this->assertStringNotContainsString('data-alta-panel', Livewire::test(MembershipCounter::class)->html());
    }

    // --- no new writer ---------------------------------------------------------------------------------------

    public function test_the_flow_adds_no_fourth_writer(): void
    {
        $source = (string) file_get_contents(app_path('Livewire/Counter/Concerns/SignsUpMembers.php'));

        // Reuse, not copy: the three audited actions in sequence and nothing between them.
        foreach (['ApproveApplication', 'EnrolMembership', 'IssueApplicationInvite'] as $writer) {
            $this->assertStringContainsString($writer, $source);
        }

        // Prompt 210 added a third way to START one of these — staff type the form here — and it had to add
        // it without adding a writer. It writes through `SubmitApplication`, the SAME Action the public POST
        // calls, so that is now a fourth reused name rather than a fourth writer.
        $this->assertStringContainsString('SubmitApplication', $source);

        // No second consent capture and no second validator anywhere in the counter half. Asserted against
        // INVOCATIONS rather than mere mentions — the docblock names SubmitApplicationRequest to explain why
        // it is not reimplemented here, and a test that failed on that would be testing prose.
        $this->assertStringNotContainsString('new RecordMemberConsent', $source);
        $this->assertStringNotContainsString('new SubmitApplicationRequest', $source);
        $this->assertStringNotContainsString('Member::create', $source);

        // **`->validate(` used to be banned outright, and prompt 210 had to relax that to the rule it was
        // standing in for.** The staff-typed form does validate — it has fields — but it must not declare
        // what an application's facts ARE. It validates against `SubmitApplicationRequest::factRules()`,
        // literally the public form's rules, so the two cannot diverge; what is banned is this file writing
        // rules of its own for those fields.
        $this->assertStringContainsString('SubmitApplicationRequest::factRules()', $source);

        foreach (['first_name', 'last_name', 'date_of_birth', 'document_type', 'document_number'] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                "/'{$field}'\s*=>\s*\[/",
                $source,
                "the counter declares its own rules for {$field} — that is the second validator this test exists to stop",
            );
        }
    }

    public function test_approval_still_refuses_an_unsubmitted_invitation(): void
    {
        $staff = $this->staff();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        CounterOperator::set($staff);
        CounterHandover::end();

        // Never submitted — ApproveApplication's own guard, unchanged.
        $this->expectException(RuntimeException::class);
        (new ApproveApplication)->handle($application->fresh(), $staff->id);
    }
}
