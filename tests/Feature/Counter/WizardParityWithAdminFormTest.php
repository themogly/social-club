<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\AnonymiseMember;
use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\AvaladorResolver;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 244 — the counter wizard collects the medical certificate the admin form does, and the sponsor field
 * says who it resolved to. The camera was never missing on main (the inputs carry `capture`); re-asserted.
 */
class WizardParityWithAdminFormTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    // --- Medical certificate parity ----------------------------------------------

    /**
     * The wizard stores a therapeutic member's medical certificate, encrypted, exactly as the admin form would.
     * Fails against `main`: `medical_cert` is not in `ApplicationShape::files()` there, so it never reaches the
     * writer and a therapeutic member is created with no evidence.
     */
    public function test_the_wizard_stores_a_medical_certificate_for_a_therapeutic_member(): void
    {
        $this->operator();
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Tere')
            ->set('altaForm.last_name', 'Peuta')
            ->set('altaForm.date_of_birth', now()->subYears(40)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->set('altaForm.email', 'tere@example.es')
            ->call('altaNext')
            ->call('altaNext')
            ->set('altaForm.is_therapeutic', true)
            ->set('altaTierId', $tier->id)
            ->set('altaMedicalCert', UploadedFile::fake()->create('certificado.pdf', 120, 'application/pdf'))
            ->call('altaNext')
            ->set('altaSignaturePath', 'data:image/png;base64,'.base64_encode('sig'))
            ->call('submitStaffAlta')
            ->assertHasNoErrors();

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertArrayHasKey('medical_cert_path', $application->payload, 'the certificate never reached the writer');
        $this->assertStringStartsWith('member-medical-certs/', $application->payload['medical_cert_path']);
        Storage::disk('documents')->assertExists($application->payload['medical_cert_path']);

        // Approving carries it onto the member, like an admin-created one.
        Livewire::test(MembershipCounter::class)
            ->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)
            ->call('approveAlta')
            ->assertSet('flashType', 'success');

        $member = Member::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertTrue($member->is_therapeutic);
        $this->assertSame($application->payload['medical_cert_path'], $member->medical_cert_path);
    }

    public function test_the_medical_cert_is_captured_in_person_on_the_wizard_not_the_online_form(): void
    {
        $wizard = (string) file_get_contents(resource_path('views/livewire/counter/partials/alta-staff-form.blade.php'));
        $online = (string) file_get_contents(resource_path('views/socio/application.blade.php'));

        $this->assertStringContainsString('data-alta-medical-cert', $wizard, 'the wizard must offer the certificate upload');
        $this->assertStringNotContainsString('medical_cert', $online, 'the online form must not upload it (in-person, Article 9)');
    }

    public function test_anonymise_deletes_the_medical_certificate(): void
    {
        Storage::disk('documents')->put('member-medical-certs/cert.pdf', 'bytes');
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'medical_cert_path' => 'member-medical-certs/cert.pdf',
            'is_therapeutic' => true,
        ]);

        (new AnonymiseMember)->handle($member);

        Storage::disk('documents')->assertMissing('member-medical-certs/cert.pdf');
        $this->assertNull($member->fresh()->medical_cert_path);
    }

    // --- The sponsor field says what it found -----------------------------------

    public function test_the_sponsor_resolver_reports_found_none_and_multiple(): void
    {
        $maria = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => 'María', 'last_name' => 'García', 'member_no' => 'M-00042',
        ]);
        // Two socios sharing a name → ambiguous.
        Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Juan', 'last_name' => 'Pérez']);
        Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Juan', 'last_name' => 'Pérez']);

        $found = AvaladorResolver::resolve($this->org->id, 'María García');
        $this->assertSame('found', $found['status']);
        $this->assertSame($maria->id, $found['member']->id);

        $byNumber = AvaladorResolver::resolve($this->org->id, 'M-00042');
        $this->assertSame('found', $byNumber['status']);
        $this->assertSame($maria->id, $byNumber['member']->id);

        $this->assertSame('none', AvaladorResolver::resolve($this->org->id, 'Nadie Existe')['status']);
        $this->assertSame('multiple', AvaladorResolver::resolve($this->org->id, 'Juan Pérez')['status']);
        $this->assertSame('empty', AvaladorResolver::resolve($this->org->id, '  ')['status']);
    }

    public function test_the_wizard_renders_the_sponsor_feedback(): void
    {
        $this->operator();
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => 'María', 'last_name' => 'García', 'member_no' => 'M-00042',
        ]);

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Nuevo')
            ->set('altaForm.last_name', 'Socio')
            ->set('altaForm.date_of_birth', now()->subYears(25)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '99999999R')
            ->call('altaNext') // → step 2, where the sponsor field (and its feedback) render
            ->set('altaForm.avalador_ref', 'María García')
            ->html();

        $this->assertStringContainsString('data-avalador-feedback="found"', $html);
        $this->assertStringContainsString('M-00042', $html);
    }

    // --- The camera, re-asserted (never missing on main) -------------------------

    public function test_the_capture_attributes_are_present_on_both_routes(): void
    {
        $wizard = (string) file_get_contents(resource_path('views/livewire/counter/partials/alta-staff-form.blade.php'));
        $online = (string) file_get_contents(resource_path('views/socio/application.blade.php'));

        // The wizard: face = front camera, document + certificate = rear camera.
        $this->assertMatchesRegularExpression('/data-alta-photo[^>]*capture="user"|capture="user"[^>]*data-alta-photo/', $wizard);
        $this->assertMatchesRegularExpression('/data-alta-scan[^>]*capture="environment"|capture="environment"[^>]*data-alta-scan/', $wizard);
        $this->assertMatchesRegularExpression('/data-alta-medical-cert[^>]*capture="environment"|capture="environment"[^>]*data-alta-medical-cert/', $wizard);

        // The online applicant form: the photo opens the front camera on a phone.
        $this->assertMatchesRegularExpression('/id="photo"[^>]*capture="user"/', $online);
    }
}
