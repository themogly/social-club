<?php

namespace Tests\Feature\Members;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\MemberApplications\Pages\EditMemberApplication;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
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
use Tests\TestCase;

/**
 * Admin audit, Phase C — an application's status has exactly ONE writer.
 *
 * `MemberApplicationForm` used to offer `status` as a free Select over every case, including APPROVED, plus
 * `reject_reason` beside it — on an Edit page whose policy requires `applications.review`, which STAFF holds
 * (prompt 174). Measured before the fix: a STAFF user opened a submitted application whose applicant was
 * **14 years old**, set the status to APPROVED and saved. The register then said the application was
 * approved while NO member existed, `resulting_member_id` was null, no versioned consent had been recorded,
 * and neither the age gate nor the duplicate search had run — because none of `ApproveApplication` was on
 * that path.
 *
 * That is a second, ungated writer to a state one Action owns, which is precisely what this codebase refuses
 * for stock (`RecordStockMovement`) and pricing (`ResolvePrice`). It also walked through prompt 174's
 * reasoning: `members.create` is withheld from STAFF *so that* they cannot produce a member without those
 * gates — and this let them record the outcome anyway.
 *
 * These are the denial tests. They assert the hole is CLOSED, not merely that the happy path still works.
 */
class ApplicationStatusHasOneWriterTest extends TestCase
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

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value); // holds applications.review, NOT members.create
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    /** A submitted application from a 14-year-old — the exact record the audit approved by hand (PENDING + submitted_at is 'submitted'). */
    private function underageSubmission(): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'status' => ApplicationStatus::PENDING,
            'submitted_at' => now()->subDay(),
            'payload' => [
                'first_name' => 'Iker',
                'last_name' => 'Salgado',
                'email' => 'iker@example.test',
                'date_of_birth' => now()->subYears(14)->toDateString(),
                'document_type' => 'DNI',
                'document_number' => '00000014A',
            ],
        ]);
    }

    public function test_the_edit_form_no_longer_offers_status_or_a_reject_reason(): void
    {
        $this->staff();
        $application = $this->underageSubmission();

        $component = Livewire::test(EditMemberApplication::class, ['record' => $application->id])->assertOk();

        // The fields are GONE from the schema, not merely hidden or disabled: a disabled field is still a
        // field, and Livewire's data is addressable by anyone who can open the page.
        $component->assertFormFieldDoesNotExist('status');
        $component->assertFormFieldDoesNotExist('reject_reason');
        $component->assertFormFieldExists('location_id'); // the one thing that is still safe to change
    }

    public function test_staff_cannot_approve_an_underage_application_through_the_edit_form(): void
    {
        $this->staff();
        $application = $this->underageSubmission();

        // The attempt the audit made, exactly: set the status on the form and save.
        Livewire::test(EditMemberApplication::class, ['record' => $application->id])
            ->fillForm(['status' => ApplicationStatus::APPROVED->value])
            ->call('save');

        $application->refresh();

        $this->assertSame(ApplicationStatus::PENDING, $application->status, 'the status must not move off the form');
        $this->assertNull($application->resulting_member_id);
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count(), 'no member may exist for an application nothing approved');
    }

    /**
     * The other half of the same hole: the create page is gone.
     *
     * An application hand-made in the panel has no invite token, so nobody could ever fill it in — and that
     * page was the second route to the free status Select. Invitations are issued by the `Invitar` action,
     * through IssueApplicationInvite.
     */
    public function test_the_resource_has_no_create_page(): void
    {
        $this->assertArrayNotHasKey('create', MemberApplicationResource::getPages());

        $this->staff();
        $this->get(MemberApplicationResource::getUrl('index'))->assertOk();
        $this->get('/member-applications/create')->assertNotFound();
    }

    /**
     * The decisions still work where they belong — the review Actions, with every gate on the path.
     *
     * On the VIEW page, not Edit: `recordActions()` is attached to the table and the view page, which is the
     * point. The edit form is for the one field that is safe to change; a decision is an Action.
     */
    public function test_the_review_actions_remain_the_way_a_decision_is_recorded(): void
    {
        $this->staff();
        $application = $this->underageSubmission();

        // Approving THIS application is refused for the right reason: the applicant is 14. The gate runs
        // because the Action is the path — which is the whole argument for removing the form field.
        Livewire::test(ViewMemberApplication::class, ['record' => $application->id])
            ->assertActionExists('approve')
            ->callAction('approve');

        $application->refresh();
        $this->assertSame(ApplicationStatus::PENDING, $application->status, 'a 14-year-old is not approved by the Action either');
        $this->assertNull($application->resulting_member_id);
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
    }
}
