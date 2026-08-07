<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ApproveApplication;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 152 — a generated invitation (PENDING, submitted_at = null) must NOT offer a review decision, and
 * approving one directly must refuse for the RIGHT reason (not submitted) rather than telling the operator a
 * non-existent applicant is underage. And a payload missing a name must never enrol a nameless row.
 */
class ApproveRequiresSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function reviewer(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::OWNER->value); // OWNER holds applications.review
        $u->locations()->sync([$this->location->id]);

        return $u;
    }

    private function unsubmittedInvitation(): MemberApplication
    {
        // Exactly what "Generar invitación" writes: pending, empty payload, never submitted.
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    private function submittedApplication(array $payloadOverrides = []): MemberApplication
    {
        return MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id,
            'status' => ApplicationStatus::PENDING,
            'payload' => array_merge([
                'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.test',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'document_type' => 'DNI', 'document_number' => '12345678Z',
                'consents' => ['membership', 'data_processing'],
            ], $payloadOverrides),
        ]);
    }

    public function test_an_unsubmitted_invitation_does_not_offer_approve(): void
    {
        Livewire::actingAs($this->reviewer())->test(ListMemberApplications::class)
            ->assertTableActionHidden('approve', $this->unsubmittedInvitation());
    }

    public function test_a_submitted_application_offers_approve_and_it_works_end_to_end(): void
    {
        $app = $this->submittedApplication();

        Livewire::actingAs($this->reviewer())->test(ListMemberApplications::class)
            ->assertTableActionVisible('approve', $app)
            ->callTableAction('approve', $app, ['allow_duplicate' => false])
            ->assertHasNoTableActionErrors();

        $app->refresh();
        $this->assertSame(ApplicationStatus::APPROVED, $app->status);
        $member = Member::query()->firstOrFail();
        $this->assertSame('María', $member->first_name);
        $this->assertNotNull($member->member_no);
    }

    public function test_approving_an_unsubmitted_record_directly_fails_saying_not_submitted(): void
    {
        $invitation = $this->unsubmittedInvitation();

        // The message must be the submission one — NOT "the applicant is underage" (there is no applicant).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('La solicitud todavía no se ha enviado: es una invitación pendiente, no una solicitud para revisar.'));
        (new ApproveApplication)->handle($invitation);
    }

    public function test_a_payload_missing_a_name_is_refused_and_creates_no_member(): void
    {
        // Submitted + a valid (adult) DOB, so it passes the submission and age gates — the ONLY defect is a
        // missing first name. Against current main this silently enrols a member with a blank name.
        $app = $this->submittedApplication(['first_name' => null]);

        try {
            (new ApproveApplication)->handle($app);
            $this->fail('Approval must be refused when a required name is missing.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(__('nombre'), $e->getMessage(), 'The refusal must name the missing field.');
        }

        $this->assertSame(0, Member::query()->count(), 'No member may be created with a blank name in the register.');
    }

    /**
     * Re-pointed by prompt 174, not weakened.
     *
     * The guarantee this defends is that the review queue — and therefore every decision, approve included —
     * is gated on `applications.review` at the POLICY, server-side, never by hiding a button. That is
     * unchanged. What changed is which role holds it: 174 grants `applications.review` to STAFF on the
     * owner's explicit instruction, because there is normally one member of staff in the club and requiring
     * a manager would mean nobody could be signed up.
     *
     * So the denial is asserted against a user with NO role, and the line 174 deliberately did not cross —
     * `members.create` staying manager-only — is asserted alongside it, which is the more important half now.
     */
    public function test_the_review_queue_is_gated_on_the_permission_not_on_a_hidden_button(): void
    {
        // No role at all → no applications.review → denied at viewAny.
        $nobody = User::factory()->create();
        $nobody->locations()->sync([$this->location->id]);

        $this->actingAs($nobody);
        $this->assertFalse(MemberApplicationResource::canViewAny());

        // STAFF now CAN review — prompt 174, the counter-first alta.
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->location->id]);

        $this->actingAs($staff);
        $this->assertTrue(MemberApplicationResource::canViewAny());

        // …but STAFF still cannot conjure a member out of nothing. Staff admit somebody who APPLIED, through
        // the audited path; the direct-enrol route stays manager-only. This is the line, asserted directly.
        $this->assertFalse($staff->can('members.create'));

        $this->actingAs($this->reviewer());
        $this->assertTrue(MemberApplicationResource::canViewAny());
    }
}
