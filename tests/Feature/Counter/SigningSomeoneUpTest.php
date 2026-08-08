<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\ConsentChannel;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
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
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 210 — there was no way for staff to fill the form in, which is why the button read wrong.
 *
 * The owner: *"I don't think it needs to say 'hand tablet over' … the staff might do it for them as well.
 * It was more just a suggestion they could hand it over, but more than likely the staff will do it for
 * them."*
 *
 * He is right, and the reason is sharper than the wording. `SignsUpMembers` had exactly two ways to begin a
 * sign-up — `handOverForAlta()` and `sendAltaInvitation()` — so **there was no staff-fills-it-in route at
 * all**. A member of staff with the person in front of them could reach the form only by handing the device
 * over, and then, if they typed it themselves, they were working on the applicant-facing public page with no
 * counter chrome and a PIN to get back out. The label was not badly chosen; it was describing the only
 * mechanism there was.
 *
 * **The compliance half.** The facts on the form are the same facts whoever types them. The consent is not:
 * `SubmitApplication` stamps a versioned consent text and locale, and that record is the club's evidence that
 * the applicant agreed to the processing of their data including Article 9 health data. Staff ticking it on
 * someone's behalf turns a record of consent GIVEN into the club's assertion that it WAS. So the staff route
 * does not produce the public form's artefact and does not pretend to — the consent row is stamped `PAPER`
 * and names the operator. The open question — whether that is enough without a scan of the signed form — was
 * **resolved by prompt 218**: it is, because the club already takes the signature on paper, and the evidence
 * is that filed form plus this row. Do not tighten the `PAPER` channel without the owner asking.
 */
class SigningSomeoneUpTest extends TestCase
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

    /** @param  array<string, mixed>  $overrides */
    private function typeTheForm(array $overrides = []): Testable
    {
        return Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm', array_merge([
                'first_name' => 'Lucía',
                'last_name' => 'García',
                'email' => 'lucia@example.es',
                'phone' => '600111222',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'address' => 'Calle Mayor 1',
                'document_type' => 'DNI',
                'document_number' => '12345678Z',
                'is_therapeutic' => false,
                'avalador_ref' => '',
            ], $overrides))
            ->set('altaConsentHeld', true)
            ->call('submitStaffAlta');
    }

    private function latestApplication(): MemberApplication
    {
        return MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function tier(): MembershipTier
    {
        return MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 2500]);
    }

    // --- The missing route -------------------------------------------------------------------

    /**
     * A member of staff signs somebody up **without handing the tablet over**, end to end.
     *
     * Fails against `main`: `submitStaffAlta` does not exist there, because the route does not exist there.
     * Asserted against the ROW, not the screen — the claim is that this produces the same `MemberApplication`
     * the public form produces.
     */
    public function test_staff_can_sign_someone_up_without_handing_the_tablet_over(): void
    {
        $this->staff();

        $this->typeTheForm();

        $this->assertFalse(CounterHandover::active(), 'the staff route handed the tablet over');

        $application = $this->latestApplication();
        $payload = $application->payload;

        $this->assertNotNull($application->submitted_at, 'the application is not in the review queue');
        $this->assertSame(ApplicationStatus::PENDING, $application->status);
        $this->assertSame($this->location->id, $application->location_id, 'the sede came from the client, not the counter');
        $this->assertSame('Lucía', $payload['first_name']);
        $this->assertSame('12345678Z', $payload['document_number']);
    }

    /** …and the payload is the same SHAPE the public form writes — one writer, one record. */
    public function test_the_staff_typed_record_has_the_same_shape_as_the_public_form(): void
    {
        $staff = $this->staff();

        $this->typeTheForm();
        $typed = $this->latestApplication()->payload;

        // The public path, for comparison: hand over, applicant submits the real form at the real token.
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $handedOver = $this->latestApplication();
        $this->fillInThePublicForm($handedOver);
        CounterOperator::set($staff);
        CounterHandover::end();

        $public = $handedOver->fresh()->payload;

        $this->assertSame(array_keys($public), array_keys($typed), 'the two routes store different payload shapes');
        foreach (['first_name', 'last_name', 'email', 'date_of_birth', 'document_type', 'document_number'] as $field) {
            $this->assertSame($public[$field], $typed[$field], "{$field} differs between the routes");
        }
        $this->assertSame($public['consent_version'], $typed['consent_version'], 'the staff route stamped a different consent version');
        $this->assertSame($public['consents'], $typed['consents'], 'the staff route captured different consents');
    }

    /** @param  array<string, mixed>  $overrides */
    private function fillInThePublicForm(MemberApplication $application, array $overrides = []): void
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        $this->post(route('socio.application.store', ['token' => $application->invite_token]), array_merge([
            'first_name' => 'Lucía',
            'last_name' => 'García',
            'email' => 'lucia@example.es',
            'phone' => '600111222',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'address' => 'Calle Mayor 1',
            'document_type' => 'DNI',
            'document_number' => '12345678Z',
            'consent_data' => '1',
            'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $token,
        ], $overrides));
    }

    // --- Nothing is skipped because staff are typing --------------------------------------

    /** The age gate refuses an under-age applicant on the staff route exactly as on the public one. */
    public function test_the_age_gate_still_refuses_an_underage_applicant_on_the_staff_route(): void
    {
        $this->staff();
        $tier = $this->tier();

        $this->typeTheForm(['date_of_birth' => now()->subYears(16)->format('Y-m-d')]);
        $application = $this->latestApplication();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)
            ->call('approveAlta');

        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count(), 'an under-age applicant became a member');
        $this->assertNotSame(ApplicationStatus::APPROVED, $application->fresh()->status);
    }

    /** The duplicate search fires on the staff route, and the override is still a reasoned, audited decision. */
    public function test_the_duplicate_search_fires_on_the_staff_route(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => 'Lucía', 'last_name' => 'García',
            'date_of_birth' => now()->subYears(30),
            'status' => MemberStatus::ACTIVE,
        ]);

        $this->typeTheForm();
        $application = $this->latestApplication();

        $review = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)
            ->call('approveAlta');

        $review->assertSet('altaDuplicateBlocked', true);
        $this->assertSame(1, Member::query()->withoutGlobalScopes()->count(), 'the duplicate was created anyway');

        // The override is available and still a decision, not a default.
        $review->call('approveAlta', true);
        $this->assertSame(2, Member::query()->withoutGlobalScopes()->count());
        $this->assertSame($staff->id, $application->fresh()->reviewed_by);
    }

    // --- The consent decision, enforced ------------------------------------------------------

    /**
     * The staff route's consent artefact is **stamped and attributed**, never silently the public form's.
     *
     * This is the assertion the compliance decision rests on: the row says PAPER, and it names the operator
     * who recorded it, so an inspection can tell the club's account of a consent from the applicant's own
     * act — and the club knows which of its records are which.
     */
    public function test_a_staff_typed_consent_is_recorded_as_paper_and_names_the_operator(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        $this->typeTheForm();
        $application = $this->latestApplication();

        $this->assertSame(ConsentChannel::PAPER->value, $application->payload['consent_channel']);
        $this->assertSame($staff->id, $application->payload['consent_attested_by']);

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)
            ->call('approveAlta');

        $member = Member::query()->withoutGlobalScopes()->sole();
        $consents = $member->consents()->withoutGlobalScopes()->get();

        $this->assertGreaterThanOrEqual(1, $consents->count(), 'no consent was recorded at all');

        foreach ($consents as $consent) {
            $this->assertSame(ConsentChannel::PAPER, $consent->channel);
            $this->assertSame($staff->id, $consent->attested_by, 'the club asserted a consent nobody is named on');
        }
    }

    /** The applicant's own tick is still exactly that — the public route is untouched by any of this. */
    public function test_the_public_form_still_records_the_applicants_own_act(): void
    {
        $staff = $this->staff();
        $tier = $this->tier();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = $this->latestApplication();
        $this->fillInThePublicForm($application);
        CounterOperator::set($staff);
        CounterHandover::end();

        $this->assertSame(ConsentChannel::APPLICANT->value, $application->fresh()->payload['consent_channel']);

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)
            ->call('approveAlta');

        foreach (Member::query()->withoutGlobalScopes()->sole()->consents()->withoutGlobalScopes()->get() as $consent) {
            $this->assertSame(ConsentChannel::APPLICANT, $consent->channel);
            $this->assertNull($consent->attested_by, 'an applicant tick was attributed to a member of staff');
        }
    }

    /**
     * **The route cannot produce an application whose consent artefact is weaker than the public form's.**
     *
     * Weaker means missing a field the public form always carries, or unattributed. The staff route's record
     * is the public form's fields PLUS the channel and the operator — strictly more information, and the
     * honest difference is that the channel says it was the club's account. It also cannot run at all
     * without the operator saying so explicitly: there is no default on that confirmation.
     */
    public function test_the_staff_route_cannot_produce_a_weaker_consent_artefact(): void
    {
        $this->staff();

        // Without the explicit confirmation, nothing is written at all.
        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.email', 'lucia@example.es')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->set('altaConsentHeld', false)
            ->call('submitStaffAlta')
            ->assertHasErrors('altaConsentHeld');

        $this->assertSame(0, MemberApplication::query()->withoutGlobalScopes()->whereNotNull('submitted_at')->count());

        // And with it, every field the public form carries is present.
        $this->typeTheForm();
        $payload = $this->latestApplication()->payload;

        foreach (['consents', 'consent_version', 'consent_locale', 'consent_channel', 'consent_attested_by'] as $key) {
            $this->assertArrayHasKey($key, $payload, "the staff route dropped {$key}");
        }
        $this->assertNotNull($payload['consent_version']);
        $this->assertNotNull($payload['consent_locale']);
    }

    /** The facts are validated by the public form's own rules — a missing required field is refused here too. */
    public function test_the_staff_form_refuses_what_the_public_form_refuses(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', '')
            ->set('altaForm.document_number', '')
            ->set('altaConsentHeld', true)
            ->call('submitStaffAlta')
            ->assertHasErrors(['altaForm.first_name', 'altaForm.document_number', 'altaForm.email', 'altaForm.date_of_birth']);

        $this->assertSame(0, MemberApplication::query()->withoutGlobalScopes()->whereNotNull('submitted_at')->count());
    }

    /**
     * A consent row written before this branch existed still means what it meant.
     *
     * The column defaults to APPLICANT, and that is the migration's whole claim: every consent recorded
     * before the staff-typed route existed WAS the applicant's own act, so no historical row changes meaning
     * and none is retro-labelled. Asserted against a row inserted without the column, which is what an
     * upgraded database holds.
     */
    public function test_a_consent_row_written_before_this_branch_still_reads_as_the_applicants_own_act(): void
    {
        $this->staff();
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);

        DB::table('consent_records')->insert([
            'id' => (string) Str::ulid(),
            'member_id' => $member->id,
            'purpose' => 'membership',
            'consent_text_version' => '1.0',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $consent = $member->consents()->withoutGlobalScopes()->sole();

        $this->assertSame(ConsentChannel::APPLICANT, $consent->channel, 'an existing consent changed meaning');
        $this->assertNull($consent->attested_by);
    }

    // --- The other two ways still work -------------------------------------------------------

    /** Handing over still works and still leaks nothing — 173's assertions, on the new panel. */
    public function test_handing_over_still_works_and_leaks_nothing(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $this->assertTrue(CounterHandover::active());

        $html = (string) $this->get(route('counter.members'))->assertOk()->getContent();

        foreach (['data-counter-topbar', 'data-alta-staff-form', 'data-alta-handover', 'data-alta-invite'] as $hook) {
            $this->assertStringNotContainsString($hook, $html, "{$hook} rendered while an applicant held the tablet");
        }
    }

    /** The emailed-invitation route still works. */
    public function test_the_emailed_invitation_route_still_works(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->set('altaInviteEmail', 'lucia@example.es')
            ->call('sendAltaInvitation');

        $application = $this->latestApplication();
        $this->assertSame('lucia@example.es', $application->applicant_email);
        $this->assertNull($application->submitted_at, 'an invitation must not arrive already submitted');
    }

    // --- Boundaries that must not have moved -------------------------------------------------

    /** 177's boundary: no document scan and no medical certificate rendered on this screen, for any role. */
    public function test_no_document_artefact_is_rendered_on_the_screen(): void
    {
        foreach ([Role::STAFF, Role::MANAGER, Role::OWNER] as $role) {
            $this->staff($role);

            $html = Livewire::test(MembershipCounter::class)
                ->call('toggleAlta')
                ->call('toggleStaffAltaForm')
                ->html();

            foreach (['member-id-scans', 'document_scan', 'medical_cert'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "[{$role->value}] the screen rendered {$forbidden}");
            }
        }
    }

    /** 194's rule: a staff form is not a second way to find a member. */
    public function test_the_staff_form_adds_no_second_member_lookup(): void
    {
        $this->staff();

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->html();

        $this->assertSame(1, preg_match_all('/data-member-lookup(?![-\w])/', $html), 'the staff form added a second lookup');
    }

    /** 203/174's gating is unchanged: a staff form does not change who may approve. */
    public function test_a_user_without_applications_review_sees_no_alta_panel(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('membership.fee.collect');
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        $html = Livewire::test(MembershipCounter::class)->html();

        $this->assertStringNotContainsString('data-alta-panel', $html);
        $this->assertStringNotContainsString('data-alta-staff-form', $html);
    }
}
