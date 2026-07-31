<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Mail\ApplicationInviteMail;
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
 * Prompt 45 — the invite can now be EMAILED (optional applicant_email), re-sent reusing the same token,
 * and the "Copiar enlace" action genuinely copies to the clipboard instead of re-dumping the URL in a
 * toast. (The mailable's render/CID assertion rides on the existing MailRenderTest via DevMail.)
 */
class ApplicationInviteEmailTest extends TestCase
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

    private function invite(string $token, array $attributes = []): MemberApplication
    {
        return MemberApplication::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $token),
            'invite_token' => $token,
            'invite_expires_at' => now()->addDays(14),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ], $attributes));
    }

    public function test_an_invite_with_an_email_is_sent_and_the_email_is_stored(): void
    {
        Mail::fake();

        Livewire::actingAs($this->owner())->test(ListMemberApplications::class)
            ->callAction('invite', ['applicant_email' => 'prospect@example.es']);

        $application = MemberApplication::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('prospect@example.es', $application->applicant_email);
        Mail::assertSent(ApplicationInviteMail::class, fn (ApplicationInviteMail $m): bool => $m->hasTo('prospect@example.es'));
    }

    public function test_an_invite_without_an_email_sends_nothing(): void
    {
        Mail::fake();

        Livewire::actingAs($this->owner())->test(ListMemberApplications::class)
            ->callAction('invite', []);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('member_applications', 1); // still created for copy/share
    }

    public function test_resend_reuses_the_same_token(): void
    {
        Mail::fake();
        $application = $this->invite('tok-resend', ['applicant_email' => 'prospect@example.es']);
        $url = $application->inviteUrl();

        Livewire::actingAs($this->owner())->test(ListMemberApplications::class)
            ->callTableAction('resend', $application);

        Mail::assertSent(ApplicationInviteMail::class, fn (ApplicationInviteMail $m): bool => $m->url === $url && $m->hasTo('prospect@example.es'));
    }

    public function test_copy_link_confirms_a_copy_instead_of_dumping_the_url(): void
    {
        $application = $this->invite('tok-copy');

        Livewire::actingAs($this->owner())->test(ListMemberApplications::class)
            ->callTableAction('copyLink', $application)
            ->assertNotified(__('Enlace copiado'));
    }
}
