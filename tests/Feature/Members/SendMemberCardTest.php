<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ApproveApplication;
use App\Actions\Members\ResolveMemberByToken;
use App\Actions\Members\SendMemberCard;
use App\Enums\IdDocumentType;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Mail\MemberCardMail;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 85 — a new member's QR card is sent automatically on creation, from BOTH creation paths, queued
 * (never synchronous), skipped cleanly with no email, and audited. The token in the mail actually resolves.
 */
class SendMemberCardTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Storage::fake('public');
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    public function test_creating_a_member_via_the_admin_form_queues_the_card_once(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner());
        $avalador = Member::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(CreateMember::class)
            ->fillForm([
                'first_name' => 'Ana', 'last_name' => 'García', 'email' => 'ana@example.test',
                'phone' => '600000000', 'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'address' => 'Calle Falsa 123', 'document_type' => IdDocumentType::DNI->value,
                'document_number' => '12345678Z', 'is_therapeutic' => false,
                'avalador_member_id' => $avalador->id, 'consent_given' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertQueued(MemberCardMail::class, 1);
        Mail::assertQueued(MemberCardMail::class, fn (MemberCardMail $m): bool => $m->hasTo('ana@example.test'));
    }

    public function test_approving_an_application_queues_the_card_exactly_once(): void
    {
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'payload' => [
                'first_name' => 'Beto', 'last_name' => 'Ruiz', 'email' => 'beto@example.test',
                'date_of_birth' => now()->subYears(28)->format('Y-m-d'),
                'document_type' => IdDocumentType::DNI->value, 'document_number' => '87654321X',
            ],
        ]);

        (new ApproveApplication)->handle($application, $this->owner()->id);

        // Exactly once — the admin CreateMember afterCreate does not run on the approval path (no double-send).
        Mail::assertQueued(MemberCardMail::class, 1);
    }

    public function test_a_member_with_no_email_queues_nothing_and_is_discoverable(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => null]);

        $this->assertFalse((new SendMemberCard)->handle($member)); // no send
        Mail::assertNothingQueued();
        $this->assertTrue($member->cardMissing()); // surfaced, not a silent failure
    }

    public function test_the_card_mail_carries_a_token_that_resolves_to_the_member(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'c@example.test']);

        (new SendMemberCard)->handle($member);

        Mail::assertQueued(MemberCardMail::class, function (MemberCardMail $mail) use ($member): bool {
            return (new ResolveMemberByToken)->handle($mail->token)?->id === $member->id;
        });
        $this->assertFalse($member->refresh()->cardMissing()); // now has a card
    }

    public function test_the_resend_action_queues_the_card_and_rotates_the_token(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'd@example.test']);

        (new SendMemberCard)->handle($member); // initial
        (new SendMemberCard)->handle($member); // resend

        Mail::assertQueued(MemberCardMail::class, 2);
        // Hash-only tokens (NOTES §B) can't be re-used, so a resend rotates: one active card, the first revoked.
        $this->assertSame(1, $member->tokens()->where('purpose', 'QR_CARD')->whereNull('revoked_at')->count());
        $this->assertSame(1, $member->tokens()->where('purpose', 'QR_CARD')->whereNotNull('revoked_at')->count());
    }

    public function test_the_send_is_audited(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'e@example.test']);

        (new SendMemberCard)->handle($member);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.card.sent']);
        // The address is never written to the (longer-retained) audit log.
        $audit = AuditLog::query()->where('action', 'member.card.sent')->latest()->firstOrFail();
        $this->assertSame('email', $audit->after['channel']);
    }
}
