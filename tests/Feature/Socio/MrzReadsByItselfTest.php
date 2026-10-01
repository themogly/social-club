<?php

namespace Tests\Feature\Socio;

use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterOperator;
use App\Support\MrzPrefill;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 346 — Ben: "So I need to upload the image [first]?" The reader starts by itself once the photo is chosen, the
 * page asks in the background (the old full-page submit reloaded the form and dropped the ID photo just taken), only
 * empty fields are filled, and a failed read says what to do. The browser half is `tests/Browser/prove-346-mrz-auto.mjs`.
 */
class MrzReadsByItselfTest extends TestCase
{
    use RefreshDatabase;

    /** ICAO 9303 TD3 specimen (UTO) — a passport, so it reads its type too. */
    private const TD3 = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n"
        .'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        RateLimiter::clear('application-mrz:127.0.0.1');
        MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', 'auto-token'),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    private function readJson(array $body): TestResponse
    {
        return $this->postJson(route('socio.application.read', ['token' => 'auto-token']), $body);
    }

    // --- 2. The background read: no reload, so the photo stays ----------------------------------------------------------

    public function test_the_read_answers_in_the_background_with_the_documents_fields(): void
    {
        $this->readJson(['mrz' => self::TD3])->assertOk()->assertJson([
            'ok' => true,
            'fields' => [
                'first_name' => 'ANNA MARIA',
                'last_name' => 'ERIKSSON',
                'document_number' => 'L898902C3',
                'date_of_birth' => '1974-08-12',
                'document_type' => 'PASSPORT',
            ],
        ]);
        $this->assertEqualsCanonicalizing(['first_name', 'last_name', 'document_number', 'date_of_birth', 'document_type'], MrzPrefill::fields('auto-token'));
    }

    public function test_the_page_no_longer_submits_itself_to_read(): void
    {
        $client = (string) file_get_contents(resource_path('js/mrz-reader.js'));

        $this->assertStringNotContainsString('form.submit()', $client, 'a page submit reloads the form and drops the photo');
        $this->assertStringContainsString("fileInput.addEventListener('change', reader.start)", $client, 'the read must start on choosing the photo');
        $this->assertStringContainsString("'Content-Type': 'application/json'", $client);
        $this->assertStringContainsString('JSON.stringify({ mrz, keep })', $client, 'only the TEXT is posted');
    }

    // --- 3. Typed values are kept, not overwritten -------------------------------------------------------------------------

    public function test_a_field_the_person_typed_is_kept_out_of_the_provisional_set(): void
    {
        $this->readJson(['mrz' => self::TD3, 'keep' => ['last_name', 'first_name']])->assertJsonPath('ok', true)
            ->assertJsonPath('fields.last_name', 'ERIKSSON'); // the page still learns what the document says (the «Usar» hint)

        $this->assertEqualsCanonicalizing(['document_number', 'date_of_birth', 'document_type'], MrzPrefill::fields('auto-token'));

        // «Usar» asks again without that field kept: now it is provisional and gated like the rest.
        $this->readJson(['mrz' => self::TD3, 'keep' => ['first_name']]);
        $this->assertContains('last_name', MrzPrefill::fields('auto-token'));
    }

    // --- 4. A failed read --------------------------------------------------------------------------------------------------

    public function test_a_failed_read_answers_not_ok_and_marks_nothing(): void
    {
        $this->readJson(['mrz' => "NOT<<AN<<MRZ\nAT<<ALL"])->assertOk()->assertJson(['ok' => false]);
        $this->assertSame([], MrzPrefill::get('auto-token'));
    }

    public function test_the_page_carries_the_tip_the_retry_and_the_two_messages(): void
    {
        $html = (string) $this->get(route('socio.application', ['token' => 'auto-token']))->getContent();

        $this->assertStringContainsString(e(__('Para rellenar tus datos automáticamente: DNI/NIE por detrás, pasaporte por la página de la foto.')), $html);
        $this->assertStringContainsString(e(__('Volver a leer el documento')), $html);
        $this->assertStringContainsString('data-pdf="'.e(__('Para rellenar tus datos automáticamente, usa una foto en lugar de un PDF.')).'"', $html);
        $this->assertStringContainsString('data-failed="'.e(__('No hemos podido leer el documento. Fotografía la cara con las líneas de letras y «<<<» (la parte de atrás del DNI o NIE; la página de la foto del pasaporte), con buena luz y sin reflejos, o rellena los datos a mano.')).'"', $html);
        // The confirmation and the «Usar» offer are on the page, hidden, ready for a background read.
        foreach (['first_name', 'last_name', 'date_of_birth', 'document_number', 'document_type'] as $field) {
            $this->assertMatchesRegularExpression('/data-mrz-prefilled="'.$field.'"\s+hidden/', $html, "{$field} has no hidden confirmation");
            $this->assertMatchesRegularExpression('/data-mrz-offer="'.$field.'" hidden/', $html, "{$field} has no hidden offer");
        }
        // Never the words the applicant should not see.
        foreach (['MRZ', 'OCR', 'dígito de control'] as $jargon) {
            $this->assertStringNotContainsString($jargon, strip_tags(substr($html, (int) strpos($html, '<main'))), "{$jargon} shown to the applicant");
        }
    }

    // --- 7. The confirmation gate is unchanged (pin) -------------------------------------------------------------------------

    public function test_an_unconfirmed_provisional_field_still_blocks_the_submission(): void
    {
        $this->readJson(['mrz' => self::TD3]);

        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $spam = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        $this->post(route('socio.application.store', ['token' => 'auto-token']), [
            'first_name' => 'ANNA MARIA', 'last_name' => 'ERIKSSON', 'email' => 'anna@example.es',
            'date_of_birth' => '1974-08-12', 'document_type' => 'PASSPORT', 'document_number' => 'L898902C3',
            'consent_data' => '1', 'consent_statutes' => '1', 'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            ApplicationSpamGuard::HONEYPOT => '', ApplicationSpamGuard::TIMESTAMP => $spam,
            'mrz_confirmed' => ['first_name' => '1', 'last_name' => '1', 'date_of_birth' => '1', 'document_type' => '1'], // not the number
        ])->assertSessionHasErrors('document_number');
    }

    // --- 6. The staff form: only empty fields, the rest offered ----------------------------------------------------------------

    private function staffForm(): Testable
    {
        $sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$sede->id]);
        $this->actingAs($staff);
        session(['counter.location_id' => $sede->id]);
        (new OpenTill)->handle($sede, 'POS-1', 10000);
        CounterOperator::set($staff);

        return Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('toggleStaffAltaForm')->set('altaPhoto', UploadedFile::fake()->image('foto.jpg'));
    }

    public function test_the_staff_form_fills_only_empty_fields_and_offers_the_document_value_for_the_rest(): void
    {
        $form = $this->staffForm()->set('altaForm.last_name', 'García López')->call('applyMrz', self::TD3);

        $form->assertSet('altaForm.last_name', 'García López')   // typed: kept
            ->assertSet('altaForm.first_name', 'ANNA MARIA')     // empty: filled
            ->assertSet('altaMrzOffered.last_name', 'ERIKSSON')
            ->assertSeeHtml('data-mrz-offer="last_name"');
        $this->assertNotContains('last_name', $form->get('altaMrzFilled'));

        $form->call('useMrzValue', 'last_name')
            ->assertSet('altaForm.last_name', 'ERIKSSON')
            ->assertDontSeeHtml('data-mrz-offer="last_name"');
        $this->assertContains('last_name', $form->get('altaMrzFilled'));
    }

    public function test_the_staff_forms_failed_read_returns_false_and_changes_nothing(): void
    {
        $form = $this->staffForm()->set('altaForm.first_name', 'Lucía');
        $form->call('applyMrz', "NOT<<AN<<MRZ\nAT<<ALL");

        $form->assertSet('altaForm.first_name', 'Lucía')->assertSet('altaMrzFilled', []);
        $this->assertStringContainsString("Boolean(await component.call('applyMrz', mrz))", (string) file_get_contents(resource_path('js/mrz-reader.js')));
    }

    public function test_a_second_read_replaces_what_the_first_read_filled_but_never_what_was_typed(): void
    {
        $other = "P<UTOSMITH<<JOHN<<<<<<<<<<<<<<<<<<<<<<<<<<<<\n"
            .'L898902C36UTO7408122F1204159ZE184226B<<<<<10';
        $form = $this->staffForm()->call('applyMrz', self::TD3)->assertSet('altaForm.last_name', 'ERIKSSON');

        $form->call('applyMrz', $other)->assertSet('altaForm.last_name', 'SMITH')->assertSet('altaForm.first_name', 'JOHN');
    }
}
