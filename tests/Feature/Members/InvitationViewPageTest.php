<?php

namespace Tests\Feature\Members;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 154 — the View page of an unredeemed invitation must read as an invitation waiting on the applicant,
 * not as a broken form. It shows the invite lifecycle, carries the list's copy-link/resend/revoke actions (only
 * while it is an outstanding invitation), and a submitted application's page is unchanged.
 */
class InvitationViewPageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function reviewer(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::OWNER->value); // applications.review + members.create

        return $u;
    }

    private function invitation(array $attrs = []): MemberApplication
    {
        return MemberApplication::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
            'invite_token' => 'tok-'.uniqid(),
            'invite_token_hash' => hash('sha256', 'tok'),
            'applicant_email' => 'invitee@example.test',
            'invite_expires_at' => now()->addDays(10),
        ], $attrs));
    }

    private function submitted(): MemberApplication
    {
        return MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id,
            'status' => ApplicationStatus::PENDING,
            'payload' => ['first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.test', 'date_of_birth' => '1994-01-01'],
        ]);
    }

    private function viewPage(User $user, MemberApplication $app): Testable
    {
        return Livewire::actingAs($user)->test(ViewMemberApplication::class, ['record' => $app->getRouteKey()]);
    }

    public function test_an_unsubmitted_invitation_shows_the_invitation_state_not_an_empty_form(): void
    {
        $this->viewPage($this->reviewer(), $this->invitation())
            ->assertSee(__('Estado de la invitación'))
            ->assertSee(__('Sin abrir'))
            ->assertDontSee(__('Datos de la solicitud')); // the empty payload panel (section) is hidden
    }

    public function test_copy_link_and_resend_are_offered_on_an_invitation_but_not_on_a_submission(): void
    {
        $reviewer = $this->reviewer();

        $this->viewPage($reviewer, $this->invitation())
            ->assertActionVisible('copyLink')
            ->assertActionVisible('resend');

        $this->viewPage($reviewer, $this->submitted())
            ->assertActionHidden('copyLink')
            ->assertActionHidden('resend');
    }

    public function test_an_expired_invitation_offers_neither_approve_nor_a_copyable_link(): void
    {
        $expired = $this->invitation(['invite_expires_at' => now()->subDay()]);

        $this->viewPage($this->reviewer(), $expired)
            ->assertActionHidden('copyLink')
            ->assertActionHidden('resend')
            ->assertActionHidden('approve')
            ->assertSee(__('El enlace ha caducado y ya no puede usarse. Genera una nueva invitación desde la lista para volver a invitar a esta persona.'));
    }

    public function test_a_submitted_application_still_renders_its_payload(): void
    {
        $this->viewPage($this->reviewer(), $this->submitted())
            ->assertSee(__('Datos de la solicitud'))
            ->assertSee('María')                          // the payload renders as before
            ->assertDontSee(__('Estado de la invitación')); // the invitation section is hidden
    }

    public function test_a_user_without_members_create_sees_no_invite_actions(): void
    {
        $reviewerOnly = User::factory()->create();
        $reviewerOnly->givePermissionTo('applications.review'); // can view the queue, but cannot manage invites

        $this->viewPage($reviewerOnly, $this->invitation())
            ->assertActionHidden('copyLink')
            ->assertActionHidden('resend')
            ->assertActionHidden('revoke');
    }
}
