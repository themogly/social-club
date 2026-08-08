<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Http\Requests\SubmitApplicationRequest;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationShape;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 215 — two hand-written copies of the application form, and the staff one was missing a third of it.
 *
 * The owner, on the staff sign-up form prompt 210 built: *"this form is not the same as the handover form —
 * missing image uploads and multiple fields. It should include all the same and the same functions, like ID
 * scan prefill."*
 *
 * Measured: the public form posts **16** fields, `BLANK_ALTA_FORM` had **10**. Missing were the member
 * `photo`, the `document_scan`, prompt 179's whole **MRZ prefill**, and `declared_monthly_g` — which feeds
 * `declared_monthly_cg`, the club's cultivation forecast and `StockCeiling::forLocation()`, so the number the
 * club plans its legal grow against was quietly short by one member every time staff signed somebody up.
 *
 * **Fixing the list would have fixed today.** There were two hand-written field lists with nothing making
 * them agree, and 210 had already got the *writer* right — so the writer was simply handed less by one caller
 * than the other, silently. `App\Support\ApplicationShape` is the one declaration now, and the guard below is
 * what makes it impossible to add a field to one route and not the other.
 */
class OneApplicationFormTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function staff(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    /** Every `name="…"` the applicant's public form posts. */
    private function publicFormFields(): array
    {
        $blade = (string) file_get_contents(resource_path('views/socio/application.blade.php'));
        preg_match_all('/\bname="([a-z_]+)"/', $blade, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Every field the staff form binds — `wire:model="altaForm.x"` plus its two upload properties. */
    private function staffFormFields(): array
    {
        $blade = (string) file_get_contents(resource_path('views/livewire/counter/partials/alta-staff-form.blade.php'));

        preg_match_all('/wire:model="altaForm\.([a-z_]+)"/', $blade, $facts);
        preg_match_all('/wire:model="(altaPhoto|altaDocumentScan)"/', $blade, $files);

        return array_values(array_unique(array_merge(
            $facts[1],
            array_map(fn (string $p): string => $p === 'altaPhoto' ? 'photo' : 'document_scan', $files[1]),
        )));
    }

    // --- The guard for the class ---------------------------------------------------------------

    /**
     * **The two routes accept the same field set** — compared programmatically, with the consent fields named
     * as the one deliberate difference.
     *
     * Fails against `main`, where the staff form is missing `photo`, `document_scan` and
     * `declared_monthly_g`. And it is the guard that stops the next field going to one form only.
     */
    public function test_both_forms_render_exactly_the_declared_field_set(): void
    {
        $declared = array_merge(array_keys(ApplicationShape::facts()), array_keys(ApplicationShape::files()));
        sort($declared);

        // The public form also posts the spam guard's two hidden fields and the consent pair; neither is a
        // fact about the applicant, and the consent difference is prompt 210's deliberate one.
        $ignore = array_merge(
            ApplicationShape::consentFields()['public'],
            ['mrz'],
        );

        $public = array_values(array_diff($this->publicFormFields(), $ignore));
        sort($public);

        $staff = $this->staffFormFields();
        sort($staff);

        $this->assertSame($declared, $public, 'the PUBLIC form drifted from App\Support\ApplicationShape');
        $this->assertSame($declared, $staff, 'the STAFF form drifted from App\Support\ApplicationShape');
    }

    /** The declaration is what the validator uses, so a new field is validated on both routes for free. */
    public function test_the_validator_derives_from_the_declaration(): void
    {
        $rules = SubmitApplicationRequest::factRules();

        foreach (array_merge(array_keys(ApplicationShape::facts()), array_keys(ApplicationShape::files())) as $field) {
            $this->assertArrayHasKey($field, $rules, "{$field} is declared but not validated");
        }
    }

    /** 210's consent difference is named, deliberate, and survives — not quietly folded into parity. */
    public function test_the_consent_difference_is_the_only_one_and_is_declared(): void
    {
        $this->assertSame(['consent_data', 'consent_statutes'], ApplicationShape::consentFields()['public']);
        $this->assertSame(['altaConsentHeld'], ApplicationShape::consentFields()['staff']);

        // …and it is NOT in the fact declaration, so parity can never be satisfied by making staff tick the
        // applicant's two acceptances.
        foreach (['consent_data', 'consent_statutes'] as $field) {
            $this->assertArrayNotHasKey($field, ApplicationShape::facts());
        }
    }

    // --- The staff route now carries what it was missing ----------------------------------------

    /**
     * A staff-created application carries a photo, a document scan and `declared_monthly_g`, and the member
     * ends up with the declared figure. Asserted against the row.
     */
    public function test_a_staff_created_application_carries_the_photo_scan_and_declared_figure(): void
    {
        Storage::fake('documents');
        $this->staff();
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0]);
        $options = array_values(array_filter((array) Settings::get('forecast_options_g', [30, 50, 60, 90]), 'is_numeric'));
        $declared = (int) $options[0];

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.email', 'lucia@example.es')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->set('altaForm.declared_monthly_g', (string) $declared)
            ->set('altaPhoto', UploadedFile::fake()->image('cara.jpg'))
            ->set('altaDocumentScan', UploadedFile::fake()->image('dni.jpg'))
            ->set('altaConsentHeld', true)
            ->call('submitStaffAlta')
            ->assertHasNoErrors();

        $payload = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->payload;

        $this->assertArrayHasKey('photo_path', $payload, 'the photo never reached the writer');
        $this->assertArrayHasKey('document_scan_path', $payload, 'the ID document never reached the writer');
        $this->assertSame($declared * 100, $payload['declared_monthly_cg'], 'the declared figure is missing or in the wrong unit');

        // Encrypted at rest on the PRIVATE disk, never a guessable path — the same vault the public form uses.
        Storage::disk('documents')->assertExists($payload['photo_path']);
        Storage::disk('documents')->assertExists($payload['document_scan_path']);
        $this->assertStringStartsWith('member-photos/', $payload['photo_path']);
        $this->assertStringStartsWith('member-id-scans/', $payload['document_scan_path']);
        $this->assertNotSame(
            'cara.jpg',
            basename($payload['photo_path']),
            'the stored filename is the uploaded one — a guessable path',
        );
    }

    /** The uploads are OPTIONAL on this route too — a counter with no camera still signs somebody up. */
    public function test_the_staff_form_submits_with_no_files_at_all(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.email', 'lucia@example.es')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->set('altaConsentHeld', true)
            ->call('submitStaffAlta')
            ->assertHasNoErrors();

        $this->assertSame(1, MemberApplication::query()->withoutGlobalScopes()->whereNotNull('submitted_at')->count());
    }

    /** Both enhancements degrade: the upload fallbacks are plain file inputs, present with or without a camera. */
    public function test_both_progressive_enhancements_degrade_to_an_upload(): void
    {
        $this->staff();

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->html();

        // The file inputs are unconditional; `capture` only ASKS a device with a camera to open it.
        $this->assertMatchesRegularExpression('/<input[^>]*data-alta-photo/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*data-alta-scan/', $html);

        // The MRZ trigger ships `hidden` and is revealed by the script — a browser that cannot run the reader
        // never shows a control that would do nothing (179's rule).
        $at = strpos($html, 'data-alta-mrz-scan');
        $this->assertNotFalse($at);
        $this->assertStringContainsString('hidden', substr($html, $at, 200));
    }

    // --- MRZ prefill, on this form too ----------------------------------------------------------

    /** A valid read fills the same four fields the public form's prefill fills. */
    public function test_mrz_prefill_fills_the_declared_four_fields(): void
    {
        $this->staff();

        // The parser's own known-good TD3 fixture (tests/Unit/MrzParserTest) — one reader, one parser, and
        // therefore one fixture: if this stops parsing, the public form has stopped reading too.
        $mrz = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n"
            .'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->call('applyMrz', $mrz);

        $filled = $component->get('altaMrzFilled');
        $this->assertNotEmpty($filled, 'the reader filled nothing at all');

        foreach ($filled as $field) {
            $this->assertContains($field, ApplicationShape::MRZ_FIELDS, "{$field} is not one of the declared MRZ fields");
            $this->assertNotSame('', $component->get('altaForm.'.$field), "{$field} was named as filled but is empty");
        }

        $this->assertContains('last_name', $filled);
        $this->assertContains('document_number', $filled);
        $component->assertSet('altaForm.last_name', 'ERIKSSON');
        $component->assertSet('altaForm.document_number', 'L898902C3');
    }

    /** A failed or absent read leaves the form usable and untouched — an imperfect reader must be safe. */
    public function test_a_failed_read_leaves_the_form_usable(): void
    {
        $this->staff();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Escrito a mano')
            ->call('applyMrz', 'not an mrz at all');

        $component->assertSet('altaForm.first_name', 'Escrito a mano');
        $this->assertSame([], $component->get('altaMrzFilled'));

        // …and an empty read does not even complain.
        $component->call('applyMrz', '')->assertSet('altaForm.first_name', 'Escrito a mano');
    }

    // --- Boundaries -----------------------------------------------------------------------------

    /** 177: nothing renders a scan at the counter. Capturing is not displaying. */
    public function test_no_scan_is_rendered_at_the_counter(): void
    {
        Storage::fake('documents');
        $this->staff();

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaDocumentScan', UploadedFile::fake()->image('dni.jpg'))
            ->html();

        foreach (['member-id-scans', 'document_scan_path', 'medical_cert'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "the counter rendered {$forbidden}");
        }
    }
}
