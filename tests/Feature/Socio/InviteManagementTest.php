<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 29 — invite links are now manageable: persisted with a RE-COPYABLE link
 * (the reported bug — it was hash-only and lost with the toast), a status board
 * (not-opened → started → submitted), a configurable expiry, and revoke.
 */
class InviteManagementTest extends TestCase
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

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        return $owner;
    }

    private function invite(string $rawToken, array $attributes = []): MemberApplication
    {
        return MemberApplication::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $rawToken),
            'invite_token' => $rawToken,
            'invite_expires_at' => now()->addDays(14),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ], $attributes));
    }

    public function test_generating_an_invite_persists_a_recoverable_link(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)->test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'handover', 'applicant_reference' => 'Referido en persona'])
            ->assertHasNoActionErrors();

        $application = MemberApplication::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($owner->id, $application->invited_by);
        $this->assertNotNull($application->invite_expires_at);
        $this->assertNotNull($application->invite_token);          // stored (encrypted), so recoverable
        $this->assertNotNull($application->inviteUrl());

        // Simulate "navigate away and return": a FRESH fetch still rebuilds the working link.
        $fresh = MemberApplication::query()->withoutGlobalScopes()->findOrFail($application->id);
        $this->get($fresh->inviteUrl())->assertOk()->assertSee(__('Enviar solicitud'));
    }

    public function test_an_expired_invite_refuses_cleanly_with_a_message_not_a_form(): void
    {
        $this->invite('expired-token', ['invite_expires_at' => now()->subDay()]);

        $response = $this->get(route('socio.application', ['token' => 'expired-token']));
        $response->assertOk();                                       // a clean page, not a 500/blank
        $response->assertSee(__('Esta invitación ha caducado. Pide una nueva a la asociación.'));
        $response->assertDontSee(__('Enviar solicitud'));           // NOT the form
    }

    public function test_revoking_an_invite_kills_its_link_immediately(): void
    {
        $application = $this->invite('revoke-token');

        // Live before revoke.
        $this->get(route('socio.application', ['token' => 'revoke-token']))->assertOk()->assertSee(__('Enviar solicitud'));

        $application->update(['revoked_at' => now()]);

        $after = $this->get(route('socio.application', ['token' => 'revoke-token']));
        $after->assertSee(__('Esta invitación ha sido anulada.'));
        $after->assertDontSee(__('Enviar solicitud'));
    }

    public function test_invite_status_transitions_not_opened_then_started_then_submitted(): void
    {
        $application = $this->invite('status-token');
        $this->assertSame('not_opened', $application->inviteStatus());

        // Opening the form marks it "started".
        $this->get(route('socio.application', ['token' => 'status-token']))->assertOk();
        $this->assertSame('started', $application->fresh()->inviteStatus());

        // Submitting marks it "submitted" — with the spam-guard fields a real form carries
        // (empty honeypot + a render token issued a few seconds ago).
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $renderToken = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        $this->post(route('socio.application.store', ['token' => 'status-token']), [
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z', 'consent_data' => '1', 'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $renderToken,
        ])->assertRedirect();

        $this->assertSame('submitted', $application->fresh()->inviteStatus());
    }

    public function test_the_blank_new_application_button_is_removed(): void
    {
        // Walk-ins use Member direct-create; prospects use the invite. No half-built blank create.
        Livewire::actingAs($this->owner())->test(ListMemberApplications::class)
            ->assertActionExists('invite')
            ->assertActionDoesNotExist('create');
    }
}
