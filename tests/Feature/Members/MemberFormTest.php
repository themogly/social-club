<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ExportMemberData;
use App\Actions\Members\IssueDocumentUrl;
use App\Enums\IdDocumentType;
use App\Enums\MemberDocumentType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Prompt 20 — the member create/edit form is now complete: the ID scan lands on the
 * private disk and is served only through a signed, access-logged URL; the monthly
 * forecast is entered in grams and stored in centigrams; RGPD consent is captured as a
 * ConsentRecord; the therapeutic toggle reveals the medical certificate and relaxes the
 * avalador rule; and the sole-association declaration is stamped and exported.
 */
class MemberFormTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Member $avalador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->avalador = Member::factory()->create(['organisation_id' => $this->org->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseFormData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'García',
            'email' => 'ana@example.test',
            'phone' => '600000000',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'address' => 'Calle Falsa 123',
            'document_type' => IdDocumentType::DNI->value,
            'document_number' => '12345678Z',
            'is_therapeutic' => false,
            'avalador_member_id' => $this->avalador->id,   // policy default is "required"
            'declared_monthly_cg' => '50.00',              // grams at the edge
            'consent_given' => true,
        ], $overrides);
    }

    private function createdMember(): Member
    {
        /** @var Member $member */
        $member = Member::query()->withoutGlobalScopes()
            ->where('id', '!=', $this->avalador->id)
            ->latest('created_at')->firstOrFail();

        return $member;
    }

    public function test_an_avalador_at_the_max_sponsees_cap_is_refused(): void
    {
        // Prompt 34: avalador_max_sponsees is now enforced — it was inert (unlimited) before.
        Settings::set('avalador_max_sponsees', 1, SettingType::INT);
        $this->actingAs($this->user(Role::OWNER));

        // The avalador already backs one member — at the configured cap of 1.
        Member::factory()->create(['organisation_id' => $this->org->id, 'avalador_member_id' => $this->avalador->id]);

        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData([
                'avalador_member_id' => $this->avalador->id,
                'document_scan_path' => UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf'),
            ]))
            ->call('create')
            ->assertHasFormErrors(['avalador_member_id']);

        // Raising the cap lets the same enrolment through — proving the SETTING is what gates it.
        Settings::set('avalador_max_sponsees', 5, SettingType::INT);
        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData([
                'avalador_member_id' => $this->avalador->id,
                'document_scan_path' => UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_the_id_scan_lands_on_the_private_disk_and_never_the_public_one(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        $file = UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf');

        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData(['document_scan_path' => $file]))
            ->call('create')
            ->assertHasNoFormErrors();

        $member = $this->createdMember();

        $this->assertNotNull($member->document_scan_path);
        $this->assertStringStartsWith('member-id-scans/', $member->document_scan_path);
        Storage::disk('documents')->assertExists($member->document_scan_path);
        Storage::disk('public')->assertMissing($member->document_scan_path);

        // The scan is mirrored into a MemberDocument (type ID) so the existing signed-URL
        // machinery serves it — never a plain disk URL.
        $this->assertDatabaseHas('member_documents', [
            'member_id' => $member->id,
            'type' => MemberDocumentType::ID->value,
            'path' => $member->document_scan_path,
        ]);
    }

    public function test_the_scan_view_url_is_access_logged_expires_and_403s_without_permission(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData(['document_scan_path' => UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf')]))
            ->call('create')
            ->assertHasNoFormErrors();

        $document = MemberDocument::query()
            ->where('member_id', $this->createdMember()->id)
            ->where('type', MemberDocumentType::ID->value)
            ->firstOrFail();

        // A role WITHOUT member.documents.view is refused — and the attempt is still logged.
        $staff = $this->user(Role::STAFF);
        try {
            (new IssueDocumentUrl)->handle($document, $staff);
            $this->fail('A user without member.documents.view must not obtain a URL.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // Issuance no longer logs (audit S2) — access is logged on the actual VIEW, asserted below.

        // The owner gets a short-lived, user-bound signed URL that streams, is logged, then expires.
        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);
        $url = (new IssueDocumentUrl)->handle($document, $owner);

        $this->get($url)->assertOk();
        $this->assertDatabaseHas('document_access_logs', [
            'actor_id' => $owner->id, 'member_document_id' => $document->id,
        ]);
        $this->travel(3600)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_the_declared_forecast_is_set_in_grams_and_stored_in_centigrams_via_the_action(): void
    {
        // Prompt 72: the forecast is no longer an inline form field — it is set through the dedicated
        // UpdateDeclaredForecast record action, which converts grams → centigrams at the edge.
        $this->actingAs($this->user(Role::OWNER));
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'declared_monthly_cg' => null]);

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->callAction('updateDeclaredForecast', ['declared_monthly_g' => '50.00'])
            ->assertHasNoActionErrors();

        $this->assertSame(5000, $member->fresh()->declared_monthly_cg); // 50.00 g → 5000 cg
    }

    public function test_submitting_create_writes_a_versioned_consent_record(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $member = $this->createdMember();
        $this->assertDatabaseHas('consent_records', [
            'member_id' => $member->id,
            'purpose' => 'membership',
            'consent_text_version' => '1.0',
        ]);
        $this->assertNotNull($member->consents()->first()?->granted_at);
    }

    public function test_create_is_blocked_without_the_consent_checkbox(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData(['consent_given' => false]))
            ->call('create')
            ->assertHasFormErrors(['consent_given']);

        $this->assertDatabaseCount('members', 1); // only the avalador from setUp
    }

    public function test_the_therapeutic_toggle_reveals_the_medical_certificate_and_relaxes_avalador(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        // The medical certificate is hidden until the therapeutic toggle is on.
        Livewire::test(CreateMember::class)
            ->assertFormFieldIsHidden('medical_cert_path')
            ->set('data.is_therapeutic', true)
            ->assertFormFieldIsVisible('medical_cert_path');

        // Non-therapeutic + no avalador is blocked (policy default is "required")…
        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData(['is_therapeutic' => false, 'avalador_member_id' => null]))
            ->call('create')
            ->assertHasFormErrors(['avalador_member_id']);

        // …but a therapeutic member with no avalador succeeds (therapeutic exemption).
        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData(['is_therapeutic' => true, 'avalador_member_id' => null, 'declared_monthly_cg' => '10.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $member = $this->createdMember();
        $this->assertTrue($member->is_therapeutic);
        $this->assertNull($member->avalador_member_id);
    }

    public function test_the_sole_association_declaration_is_stamped_and_exported(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(CreateMember::class)
            ->fillForm($this->baseFormData(['sole_association_declared_at' => true]))
            ->call('create')
            ->assertHasNoFormErrors();

        $member = $this->createdMember();
        $this->assertNotNull($member->sole_association_declared_at);

        // It appears in the RGPD data export.
        $export = (new ExportMemberData)->handle($member);
        $this->assertArrayHasKey('sole_association_declared_at', $export['member']);
        $this->assertNotNull($export['member']['sole_association_declared_at']);
    }

    public function test_system_managed_fields_are_not_exposed_on_the_form(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(CreateMember::class)
            ->assertFormFieldDoesNotExist('member_no')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('carencia_ends_at')
            ->assertFormFieldDoesNotExist('document_hash')
            ->assertFormFieldDoesNotExist('anonymised_at')
            ->assertFormFieldDoesNotExist('joined_at')
            ->assertFormFieldDoesNotExist('left_at');
    }
}
