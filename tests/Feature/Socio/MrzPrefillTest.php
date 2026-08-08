<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\MrzFieldStat;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\MrzPrefill;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prompt 179 (rewritten) — read the ID in the BROWSER, parse it on the server.
 *
 * The earlier 179's premise was false: `MrzParser` parses already-OCR'd text, the only OCR was an
 * undeclared `tesseract` shell-out inside a CLI command, so nothing could read an image and the read rate
 * was not low but zero. The owner chose in-browser reading, which is stronger on data protection than a
 * server binary would have been: **the image never leaves the applicant's device in order to be read.**
 *
 * So the assertion that pins the whole privacy argument is
 * `test_the_read_endpoint_takes_text_and_never_an_image`. The rest is what makes an imperfect reader safe:
 * every prefilled field is provisional until confirmed, enforced SERVER-side.
 *
 * Canonical ICAO 9303 fixtures, reused from prompt 128's `MrzParserTest` — one parser, one set of examples.
 */
class MrzPrefillTest extends TestCase
{
    use RefreshDatabase;

    private const TD3 = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n"
        .'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    private const TD1 = "I<UTOD231458907<<<<<<<<<<<<<<<\n"
        ."7408122F1204159UTO<<<<<<<<<<<6\n"
        .'ERIKSSON<<ANNA<MARIA<<<<<<<<<<';

    /** The same TD3 with a broken document-number check digit (6 → 5). */
    private const TD3_BROKEN = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n"
        .'L898902C35UTO7408122F1204159ZE184226B<<<<<10';

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        RateLimiter::clear('application-mrz:127.0.0.1');
    }

    private function invite(string $token = 'mrz-token'): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $token),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    private function read(string $token, string $mrz): TestResponse
    {
        return $this->post(route('socio.application.read', ['token' => $token]), ['mrz' => $mrz]);
    }

    private function submit(string $token, array $fields = []): TestResponse
    {
        return $this->post(route('socio.application.store', ['token' => $token]), array_merge([
            'first_name' => 'ANNA MARIA',
            'last_name' => 'ERIKSSON',
            'email' => 'anna@example.es',
            'date_of_birth' => '1974-08-12',
            'document_type' => 'DNI',
            'document_number' => 'L898902C3',
            'consent_data' => '1',
            'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $this->agedToken(),
        ], $fields));
    }

    private function agedToken(): string
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $token;
    }

    // --- the privacy argument, pinned --------------------------------------------------------------------

    public function test_the_read_endpoint_takes_text_and_never_an_image(): void
    {
        $this->invite();

        // The whole point: OCR happens on the applicant's device and only the MRZ STRING crosses the wire.
        $this->read('mrz-token', self::TD3)->assertRedirect();
        $this->assertNotEmpty(MrzPrefill::get('mrz-token'));

        // The client module posts text, not a file — asserted against the source so it cannot regress into
        // uploading the image "just for reading".
        $client = (string) file_get_contents(resource_path('js/mrz-reader.js'));
        $this->assertStringContainsString('data-mrz-input', $client);
        $this->assertStringNotContainsString('FormData', $client);
        $this->assertStringNotContainsString('.append(', $client);

        // …and the endpoint itself has no file handling at all.
        $controller = (string) file_get_contents(app_path('Http/Controllers/ApplicationController.php'));
        $readMethod = substr($controller, strpos($controller, 'public function read('));
        $readMethod = substr($readMethod, 0, strpos($readMethod, 'private function backToForm'));
        $this->assertStringNotContainsString('hasFile', $readMethod);
        $this->assertStringNotContainsString('storeUpload', $readMethod);
    }

    public function test_the_raw_mrz_reaches_no_log_no_response_and_no_stored_field(): void
    {
        $application = $this->invite();
        $logFile = storage_path('logs/laravel.log');
        $before = file_exists($logFile) ? (string) file_get_contents($logFile) : '';

        $response = $this->read('mrz-token', self::TD3);

        // Not echoed back…
        $this->assertStringNotContainsString('L898902C36UTO', $response->getContent());
        // …not written to the log…
        $after = file_exists($logFile) ? (string) file_get_contents($logFile) : '';
        $this->assertStringNotContainsString('L898902C36UTO', substr($after, strlen($before)));
        // …and not held in the session: only the FIELDS it yielded are kept, for one request's worth of use.
        $this->assertStringNotContainsString('L898902C36UTO', json_encode(MrzPrefill::get('mrz-token')));
        $this->assertNull(data_get($application->fresh()->payload, 'mrz'));
    }

    // --- provisional, and confirmed ------------------------------------------------------------------------

    public function test_a_valid_read_prefills_the_fields_and_marks_every_one_unconfirmed(): void
    {
        $this->invite();

        $this->read('mrz-token', self::TD3);

        $prefill = MrzPrefill::get('mrz-token');
        $this->assertSame('ERIKSSON', $prefill['last_name']);
        $this->assertSame('ANNA MARIA', $prefill['first_name']);
        $this->assertSame('L898902C3', $prefill['document_number']);
        $this->assertSame('1974-08-12', $prefill['date_of_birth']);

        $html = $this->get(route('socio.application', ['token' => 'mrz-token']))->getContent();
        foreach (array_keys($prefill) as $field) {
            $this->assertStringContainsString('data-mrz-prefilled="'.$field.'"', $html, "$field is not marked");
            $this->assertStringContainsString('name="mrz_confirmed['.$field.']"', $html, "$field has no confirmation");
        }
    }

    public function test_the_form_cannot_be_submitted_while_a_prefilled_field_is_unconfirmed(): void
    {
        $application = $this->invite();
        $this->read('mrz-token', self::TD3);

        // Server-side, not only in the browser — a confirmation enforced only in the page is decorative,
        // and the confirmation is the entire reason an imperfect reader is safe to ship.
        $this->submit('mrz-token')->assertSessionHasErrors(['first_name', 'last_name', 'document_number', 'date_of_birth']);
        $this->assertNull($application->fresh()->submitted_at);
    }

    public function test_confirming_every_field_lets_it_through(): void
    {
        $application = $this->invite();
        $this->read('mrz-token', self::TD3);

        $this->submit('mrz-token', ['mrz_confirmed' => [
            'first_name' => '1', 'last_name' => '1', 'document_number' => '1', 'date_of_birth' => '1',
        ]])->assertRedirect();

        $this->assertNotNull($application->fresh()->submitted_at);
    }

    public function test_a_form_with_no_prefill_is_completely_unaffected(): void
    {
        $application = $this->invite();

        // An applicant who never scans sees no difference at all.
        $this->submit('mrz-token')->assertRedirect();
        $this->assertNotNull($application->fresh()->submitted_at);

        $html = $this->get(route('socio.application', ['token' => 'nope']))->getStatusCode();
        $this->assertSame(404, $html);
    }

    // --- a failed read is an ordinary outcome ---------------------------------------------------------------

    public function test_an_unreadable_scan_prefills_nothing_and_shows_no_error(): void
    {
        $this->invite();

        $response = $this->read('mrz-token', "just some ocr noise\nnot an mrz at all");

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], MrzPrefill::get('mrz-token'));
    }

    public function test_a_broken_check_digit_never_prefills(): void
    {
        $this->invite();

        // The parser is correct-or-invalid (prompt 128) precisely so a mis-read cannot silently prefill a
        // wrong document number. This asserts the CALLER honours that.
        $this->read('mrz-token', self::TD3_BROKEN)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], MrzPrefill::get('mrz-token'));
    }

    public function test_both_td1_and_td3_prefill(): void
    {
        $this->invite('td3');
        $this->invite('td1');
        MemberApplication::query()->withoutGlobalScopes()->latest('id')->first()
            ->update(['invite_token_hash' => hash('sha256', 'td1')]);

        $this->read('td3', self::TD3);
        $this->assertSame('L898902C3', MrzPrefill::get('td3')['document_number']);

        // A Spanish DNI is TD1 — three lines, on the BACK of the card.
        $this->read('td1', self::TD1);
        $this->assertSame('D23145890', MrzPrefill::get('td1')['document_number']);
    }

    // --- prefill fills blanks, it does not correct people ----------------------------------------------------

    public function test_prefill_never_overwrites_a_value_the_applicant_typed(): void
    {
        $this->invite();

        // Typed BEFORE the scan: the read comes back and must leave it alone.
        $this->from(route('socio.application', ['token' => 'mrz-token']))
            ->post(route('socio.application.read', ['token' => 'mrz-token']), [
                'mrz' => self::TD3,
                'first_name' => 'Lucía',
            ]);

        $html = $this->get(route('socio.application', ['token' => 'mrz-token']))->getContent();

        // `old()` wins over the prefill in the rendered value.
        $this->assertMatchesRegularExpression('/id="first_name"[^>]*value="Lucía"/', $html);
        $this->assertStringNotContainsString('value="ANNA MARIA"', $html);
    }

    // --- the read rate, measured from real use ----------------------------------------------------------------

    public function test_a_correction_is_counted_and_a_kept_value_is_not(): void
    {
        $this->invite();
        $this->read('mrz-token', self::TD3);

        // Keeps the document number, corrects the first name.
        $this->submit('mrz-token', [
            'first_name' => 'ANNA MARIE',
            'mrz_confirmed' => ['first_name' => '1', 'last_name' => '1', 'document_number' => '1', 'date_of_birth' => '1'],
        ])->assertRedirect();

        $stats = MrzFieldStat::query()->withoutGlobalScopes()->get()->keyBy('field');

        $this->assertSame(1, $stats['first_name']->prefills);
        $this->assertSame(1, $stats['first_name']->corrections);
        $this->assertSame(1, $stats['document_number']->prefills);
        $this->assertSame(0, $stats['document_number']->corrections);
        $this->assertSame(100, $stats['first_name']->correctionRate());
        $this->assertSame(0, $stats['document_number']->correctionRate());
    }

    public function test_the_corrected_value_is_what_gets_stored(): void
    {
        $application = $this->invite();
        $this->read('mrz-token', self::TD3);

        $this->submit('mrz-token', [
            'first_name' => 'ANNA MARIE',
            'mrz_confirmed' => ['first_name' => '1', 'last_name' => '1', 'document_number' => '1', 'date_of_birth' => '1'],
        ]);

        $this->assertSame('ANNA MARIE', data_get($application->fresh()->payload, 'first_name'));
    }

    public function test_the_metric_records_counts_only_and_nothing_identifying(): void
    {
        $this->invite();
        $this->read('mrz-token', self::TD3);
        $this->submit('mrz-token', ['mrz_confirmed' => [
            'first_name' => '1', 'last_name' => '1', 'document_number' => '1', 'date_of_birth' => '1',
        ]]);

        $rows = DB::table('mrz_field_stats')->get();
        $this->assertGreaterThan(0, $rows->count());

        // Nothing in this table can reconstruct a person or a document.
        $serialised = json_encode($rows);
        foreach (['ERIKSSON', 'ANNA', 'L898902C3', '1974-08-12', 'anna@example.es'] as $identifying) {
            $this->assertStringNotContainsString($identifying, $serialised, "the metric leaks \"$identifying\"");
        }
        $this->assertSame(
            ['id', 'organisation_id', 'field', 'prefills', 'corrections', 'created_at', 'updated_at'],
            Schema::getColumnListing('mrz_field_stats'),
        );
    }

    public function test_the_prefill_does_not_survive_the_submission(): void
    {
        $this->invite();
        $this->read('mrz-token', self::TD3);

        $this->submit('mrz-token', ['mrz_confirmed' => [
            'first_name' => '1', 'last_name' => '1', 'document_number' => '1', 'date_of_birth' => '1',
        ]]);

        // The next person handed this tablet must not inherit the last one's read.
        $this->assertSame([], MrzPrefill::get('mrz-token'));
    }

    // --- the bundle is not on the critical path ------------------------------------------------------------------

    public function test_the_ocr_engine_is_not_requested_on_page_load(): void
    {
        $this->invite();

        $html = $this->get(route('socio.application', ['token' => 'mrz-token']))->getContent();

        // A WASM bundle is megabytes. An applicant who never scans, or who is on a slow connection, must
        // not pay for it — so the engine is a dynamic import inside the click handler, and nothing
        // references it from the page.
        $this->assertStringNotContainsString('tesseract', strtolower($html));
        $this->assertStringNotContainsString('/ocr/', $html);

        $client = (string) file_get_contents(resource_path('js/mrz-reader.js'));
        $this->assertStringContainsString("await import('tesseract.js')", $client);
        $this->assertStringNotContainsString("from 'tesseract.js'", $client, 'a static import would ship it on page load');
    }

    public function test_the_engine_is_served_same_origin_and_never_from_a_cdn(): void
    {
        $client = (string) file_get_contents(resource_path('js/mrz-reader.js'));

        // Prompt 128 ruled out sending Article 9 material to a third party; the same reasoning keeps a
        // third party off the critical path of an identity flow when it is avoidable, and it is.
        foreach (['unpkg.com', 'jsdelivr', 'cdn.', 'https://'] as $offsite) {
            $this->assertStringNotContainsString($offsite, $client, "the reader reaches offsite via \"$offsite\"");
        }
        $this->assertStringContainsString("'/ocr/worker.min.js'", $client);
    }

    // --- the unauthenticated route is bounded --------------------------------------------------------------------

    public function test_the_read_route_is_rate_limited_and_bounds_its_input(): void
    {
        $this->invite();

        // An MRZ is at most three lines of 44 characters; anything larger is not an MRZ.
        $this->read('mrz-token', str_repeat('A', 500));
        $this->assertSame([], MrzPrefill::get('mrz-token'));

        for ($i = 0; $i < 20; $i++) {
            $this->read('mrz-token', self::TD3);
        }
        MrzPrefill::forget('mrz-token');

        $this->read('mrz-token', self::TD3);
        $this->assertSame([], MrzPrefill::get('mrz-token'), 'the 21st read in an hour was not refused');
    }

    public function test_a_dead_invite_cannot_be_read_against(): void
    {
        $application = $this->invite();
        $application->update(['status' => ApplicationStatus::APPROVED]);

        $this->read('mrz-token', self::TD3)->assertNotFound();
    }
}
