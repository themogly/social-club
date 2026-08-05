<?php

namespace Tests\Feature\Organisation;

use App\Actions\Organisation\UpdateOrganisationIdentity;
use App\Enums\Role;
use App\Filament\Pages\ManageOrganisationIdentity;
use App\Mail\LockdownReactivationMail;
use App\Mail\MembershipReminderMail;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\OrganisationIdentity;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 159 — the club can finally edit its own identity (name, legal name, CIF/NIF, address, contacts,
 * logo). These pin: the single-writer + audit, the legal-name-after-documents decision, the logo feeding the
 * email letterhead + PDF (with the name-wordmark fallback), the contact email as member-mail Reply-To (never
 * on the lockdown mail), and prompt 115's RAT-needs-a-legal-name rule surviving.
 */
class OrganisationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
        $this->org = Organisation::factory()->create(['legal_name' => null, 'logo_path' => null, 'contact_email' => null]);
        app(ActiveScope::class)->setOrganisation($this->org->id);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // --- The writer + audit ---------------------------------------------------------

    public function test_update_writes_the_columns_and_audits_before_and_after(): void
    {
        (new UpdateOrganisationIdentity)->handle($this->org, [
            'name' => 'Club Verde',
            'legal_name' => 'Asociación Club Verde',
            'tax_id' => 'G12345678',
            'address' => 'Calle Falsa 1',
            'contact_email' => 'hola@clubverde.es',
            'contact_phone' => '+34 600 000 000',
        ]);

        $fresh = $this->org->fresh();
        $this->assertSame('Asociación Club Verde', $fresh->legal_name);
        $this->assertSame('G12345678', $fresh->tax_id);
        $this->assertSame('hola@clubverde.es', $fresh->contact_email);

        $audit = AuditLog::query()->where('action', 'organisation.identity.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->before['legal_name']);                       // both values recorded
        $this->assertSame('Asociación Club Verde', $audit->after['legal_name']);
    }

    // --- legal_name after documents exist ------------------------------------------

    public function test_changing_legal_name_feeds_new_documents_but_leaves_existing_snapshots_untouched(): void
    {
        $this->org->update(['legal_name' => 'Vieja SL']);
        $this->assertSame('Vieja SL', OrganisationIdentity::for($this->org->fresh())['display_name']);

        // A statutory document already generated snapshots the controller name at generation time.
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $document = MemberDocument::create([
            'member_id' => $member->id,
            'type' => 'REGISTRATION_FORM',
            'path' => 'documents/old.pdf',
            'snapshot' => ['controller' => 'Vieja SL'],
        ]);

        (new UpdateOrganisationIdentity)->handle($this->org, ['name' => $this->org->name, 'legal_name' => 'Nueva SL']);

        // New documents print the new name; the already-issued one keeps what it said.
        $this->assertSame('Nueva SL', OrganisationIdentity::for($this->org->fresh())['display_name']);
        $this->assertSame('Vieja SL', $document->fresh()->snapshot['controller']);
    }

    // --- Logo → email letterhead + PDF, with the wordmark fallback ------------------

    public function test_a_set_logo_feeds_the_mail_letterhead_and_the_pdf_data_uri(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('org-logos/logo.png', $png);
        $this->org->update(['logo_path' => 'org-logos/logo.png']);

        // Email CID embed sees raw bytes; the PDF sees a base64 data URI.
        $mailLogo = OrganisationIdentity::mailLogo();
        $this->assertNotNull($mailLogo);
        $this->assertSame($png, $mailLogo['data']);

        $this->assertStringStartsWith('data:image/', (string) OrganisationIdentity::for($this->org->fresh())['logo']);
    }

    public function test_without_a_logo_the_wordmark_fallback_holds(): void
    {
        $this->assertNull(OrganisationIdentity::mailLogo());                 // → mail shell shows the name wordmark
        $this->assertNull(OrganisationIdentity::for($this->org->fresh())['logo']);
    }

    // --- Reply-To on member mail, never on the lockdown mail ------------------------

    public function test_contact_email_becomes_the_reply_to_on_member_mail(): void
    {
        $this->org->update(['contact_email' => 'club@ejemplo.es', 'name' => 'Club Ejemplo']);

        $mail = new MembershipReminderMail('Ana', '2026-12-31');
        $mail->assertHasReplyTo('club@ejemplo.es');
    }

    public function test_no_reply_to_when_no_contact_email_is_set(): void
    {
        $this->assertSame([], OrganisationIdentity::replyTo());
    }

    public function test_the_lockdown_reactivation_mail_never_invites_a_reply(): void
    {
        // The reactivation mail must not carry the club Reply-To — it is operational, not a conversation.
        $source = file_get_contents((new \ReflectionClass(LockdownReactivationMail::class))->getFileName());
        $this->assertStringNotContainsString('replyTo', $source);
    }

    // --- Prompt 115: the RAT still refuses without a legal name ---------------------

    public function test_the_rat_needs_a_legal_name(): void
    {
        $this->assertFalse(OrganisationIdentity::hasLegalName($this->org->fresh()));

        $this->org->update(['legal_name' => 'Asociación X']);
        $this->assertTrue(OrganisationIdentity::hasLegalName($this->org->fresh()));
    }

    // --- The Filament page: save, audit, denial ------------------------------------

    public function test_the_page_saves_and_notifies(): void
    {
        Livewire::test(ManageOrganisationIdentity::class)
            ->set('data.name', 'Club Livewire')
            ->set('data.legal_name', 'Asociación Livewire')
            ->set('data.contact_email', 'lw@ejemplo.es')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertSame('Asociación Livewire', $this->org->fresh()->legal_name);
    }

    public function test_an_oversized_or_wrong_type_logo_is_rejected(): void
    {
        // 4 MB — over the 1 MB cap (a heavy logo in every email is a deliverability problem).
        Livewire::test(ManageOrganisationIdentity::class)
            ->set('data.logo_path', [UploadedFile::fake()->create('huge.png', 4096, 'image/png')])
            ->call('save')
            ->assertHasErrors('data.logo_path');

        // A non-image file is refused by the accepted-types whitelist.
        Livewire::test(ManageOrganisationIdentity::class)
            ->set('data.logo_path', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->call('save')
            ->assertHasErrors('data.logo_path');
    }

    public function test_the_identity_screen_is_owner_only(): void
    {
        $this->assertTrue(ManageOrganisationIdentity::canAccess());

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $this->actingAs($manager);
        $this->assertFalse(ManageOrganisationIdentity::canAccess());

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $this->actingAs($staff);
        $this->assertFalse(ManageOrganisationIdentity::canAccess());
    }
}
