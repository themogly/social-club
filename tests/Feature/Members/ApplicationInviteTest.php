<?php

namespace Tests\Feature\Members;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Mail\ApplicationInviteMail;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 149 — generating an invitation must succeed or fail as one thing, and mail must never be what
 * decides. Every failed synchronous send used to commit the invitation, hide its link, and 500 the operator.
 * Run on MySQL (project default).
 */
class ApplicationInviteTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
    }

    public function test_with_mail_hard_failing_the_invitation_still_succeeds_and_the_link_is_shown(): void
    {
        // Simulate the live failure: the mail transport throws (e.g. the Resend SDK absent).
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Class "Resend" not found'));

        Livewire::test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'email', 'applicant_email' => 'Prospect@Club.ES'])
            ->assertHasNoActionErrors()
            ->assertNotified(); // the persistent link notification still fires

        // The invitation exists exactly once, with its (normalised) email — the send did not take the action down.
        $application = MemberApplication::query()->firstOrFail();
        $this->assertSame('prospect@club.es', $application->applicant_email);
        $this->assertNotNull($application->inviteUrl());
    }

    public function test_the_email_path_queues_the_mail_rather_than_sending_it_inline(): void
    {
        Mail::fake();

        Livewire::test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'email', 'applicant_email' => 'a@club.es'])
            ->assertHasNoActionErrors();

        Mail::assertQueued(ApplicationInviteMail::class);
        Mail::assertNothingSent(); // never inline
    }

    public function test_the_handover_path_requires_an_identifier_and_produces_a_link(): void
    {
        // Missing the reference → refused with a form error, no row created.
        Livewire::test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'handover', 'applicant_reference' => ''])
            ->assertHasActionErrors(['applicant_reference']);
        $this->assertSame(0, MemberApplication::query()->count());

        // With the reference → an attributable, link-only invitation.
        Livewire::test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'handover', 'applicant_reference' => 'Referido por Ana'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $application = MemberApplication::query()->firstOrFail();
        $this->assertSame('Referido por Ana', $application->applicant_reference);
        $this->assertNull($application->applicant_email);
        $this->assertNotNull($application->inviteUrl());
    }

    public function test_an_invitation_is_redeemable_through_the_public_application_form(): void
    {
        Mail::fake();
        Livewire::test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'handover', 'applicant_reference' => 'Ana']);

        $application = MemberApplication::query()->firstOrFail();
        // The raw token (decrypted from invite_token) opens the one public member route.
        $this->get(route('socio.application', ['token' => $application->invite_token]))->assertOk();
    }

    public function test_the_membership_sweep_completes_when_a_members_mail_throws_and_reports_it(): void
    {
        // Every queued send throws (mail infra down). The nightly run must NOT abort for the rest.
        Mail::shouldReceive('to')->andThrow(new RuntimeException('mail down'));

        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'due@club.es', 'status' => MemberStatus::ACTIVE]);
        $membership = Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
            'expires_at' => CarbonImmutable::now()->addDays(3),
        ]);

        $this->artisan('memberships:sweep')->assertSuccessful();

        // The loop ran past the mail failure: the once-per-period marker was still stamped.
        $this->assertNotNull($membership->refresh()->reminder_sent_for);
    }
}
