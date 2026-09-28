<?php

namespace Tests\Feature\Members;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Members\SendApplicationInvite;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Mail\ApplicationInviteMail;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 287 — "Enviar invitación" at the counter never sent the email. 149 split CREATING an invitation
 * (IssueApplicationInvite) from MAILING it ("the caller queues it, best-effort"); the panel's callers mailed, the counter's
 * — added later — did not, and told staff "Invitación enviada". Now ONE sender queues the invitation email for every
 * caller: the panel's Invitar and Reenviar, and the counter's invite card and its Reenviar.
 */
class SendApplicationInviteTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->attach($this->location->id);
        $this->actingAs($this->owner);
    }

    private function invite(?string $email = 'lucia@example.es'): MemberApplication
    {
        return (new IssueApplicationInvite)->handle($this->owner, $this->location->id, $email, $email === null ? 'Aval de Marta' : null);
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_the_panel_invitar_and_reenviar_queue_through_the_one_sender(): void
    {
        Mail::fake();

        Livewire::test(ListMemberApplications::class)
            ->callAction('invite', ['invite_mode' => 'email', 'applicant_email' => 'lucia@example.es', 'location_id' => $this->location->id])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Invitación creada y email en cola'));
        Mail::assertQueued(ApplicationInviteMail::class, 1);

        $application = MemberApplication::query()->withoutGlobalScopes()->sole();
        Livewire::test(ListMemberApplications::class)
            ->callTableAction('resend', $application)
            ->assertNotified(__('Invitación reenviada (en cola)'));
        Mail::assertQueued(ApplicationInviteMail::class, 2);

        $this->assertSame(2, AuditLog::query()->where('action', 'application.invite.sent')->count());
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    public function test_staff_with_panel_access_can_resend_but_not_revoke(): void
    {
        $application = $this->invite();

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->givePermissionTo('panel.access');
        $staff->locations()->attach($this->location->id);
        $this->actingAs($staff);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->assertTrue($staff->can('applications.review'));
        $this->assertFalse($staff->can('members.create'));

        Livewire::test(ListMemberApplications::class)
            ->assertTableActionVisible('resend', $application)
            ->assertTableActionHidden('revoke', $application);
    }

    // 7 -------------------------------------------------------------------------------------------------------------

    public function test_the_sender_refuses_anything_but_an_outstanding_emailed_invitation(): void
    {
        Mail::fake();

        $approved = $this->invite('a@example.es');
        $approved->forceFill(['status' => ApplicationStatus::APPROVED])->saveQuietly();
        $rejected = $this->invite('b@example.es');
        $rejected->forceFill(['status' => ApplicationStatus::REJECTED])->saveQuietly();
        $revoked = $this->invite('c@example.es');
        $revoked->forceFill(['revoked_at' => now()])->saveQuietly();
        $expired = $this->invite('d@example.es');
        $expired->forceFill(['invite_expires_at' => now()->subDay()])->saveQuietly();
        $noEmail = $this->invite(null);

        foreach ([$approved, $rejected, $revoked, $expired, $noEmail] as $application) {
            $this->assertFalse((new SendApplicationInvite)->handle($application->fresh()));
        }

        Mail::assertNothingQueued();
        $this->assertTrue((new SendApplicationInvite)->handle($this->invite('e@example.es')));
        Mail::assertQueued(ApplicationInviteMail::class, 1);
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    public function test_the_audit_entry_holds_neither_the_address_nor_the_token(): void
    {
        Mail::fake();
        $application = $this->invite('lucia@example.es');

        (new SendApplicationInvite)->handle($application);

        $entry = AuditLog::query()->where('action', 'application.invite.sent')->sole();
        $this->assertSame($application->id, $entry->auditable_id);
        $this->assertTrue((bool) data_get($entry->after, 'queued'));

        $stored = json_encode([$entry->before, $entry->after]) ?: '';
        $this->assertStringNotContainsString('lucia', $stored);
        $this->assertStringNotContainsString((string) $application->invite_token, $stored);
        $this->assertStringNotContainsString('example.es', $stored);
    }
}
