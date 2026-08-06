<?php

namespace Tests\Feature\Socio;

use App\Actions\Members\ApproveApplication;
use App\Console\Commands\PruneApplications;
use App\Enums\ApplicationStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\AuditLog;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\DocumentUpload;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prompt 178 — ID capture on the application form. This is prompt 155's part B, which was specified and
 * escalated rather than built: whether an UNAUTHENTICATED public form should accept an upload of someone's
 * identity document is a data-controller decision, not a defect. The controller decided capture at the
 * counter PLUS an optional upload here, and this executes 155's recorded spec.
 *
 * The upload is a compliance artefact and Article 9 material, so what is asserted here is mostly what must
 * NOT happen: it cannot be required, it cannot reach the public disk, it cannot be read without the key,
 * it cannot outlive an application nobody approved, and it cannot be served without the permission and the
 * access log.
 *
 * The face photo (prompt 157) is a DIFFERENT artefact for a different purpose and is deliberately not
 * merged with this one — asserted, because merging them is the obvious wrong turn.
 */
class ApplicationIdCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        RateLimiter::clear('application-upload:127.0.0.1');
        // Isolated: DocumentVault encrypts BEFORE it writes, so faking the disk keeps the encryption
        // assertions honest while stopping the suite from leaving real ID scans in storage/.
        Storage::fake('documents');
    }

    private function invite(string $rawToken): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $rawToken),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    private function humanFields(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'María',
            'last_name' => 'García',
            'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI',
            'document_number' => '12345678Z',
            'consent_data' => '1',
            'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $this->agedToken(ApplicationSpamGuard::MIN_SECONDS + 2),
        ], $overrides);
    }

    private function agedToken(int $ageSeconds): string
    {
        $this->travelTo(now()->subSeconds($ageSeconds));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $token;
    }

    private function submit(string $token, array $fields): TestResponse
    {
        return $this->post(route('socio.application.store', ['token' => $token]), $fields);
    }

    // --- it lands, encrypted, on the private disk -----------------------------------------------------

    public function test_an_uploaded_id_lands_encrypted_on_the_private_disk(): void
    {
        $application = $this->invite('scan-token');

        $this->submit('scan-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));

        $path = data_get($application->fresh()->payload, 'document_scan_path');
        $this->assertNotNull($path, 'the scan was not stored');
        $this->assertStringStartsWith('member-id-scans/', $path, 'the scan is not in the ID-scan directory');

        // On the PRIVATE documents disk, and nowhere near the public one.
        Storage::disk('documents')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_stored_bytes_are_not_the_plaintext(): void
    {
        $application = $this->invite('cipher-token');
        $file = UploadedFile::fake()->image('dni.jpg');
        $plaintext = file_get_contents($file->getRealPath());

        $this->submit('cipher-token', $this->humanFields(['document_scan' => $file]));

        $stored = Storage::disk('documents')->get(data_get($application->fresh()->payload, 'document_scan_path'));

        // Encrypted at rest: the ciphertext is not the file, and without the key it does not come back.
        $this->assertNotSame($plaintext, $stored);
        $this->assertSame($plaintext, Crypt::decryptString($stored));
    }

    public function test_it_is_not_recoverable_with_a_different_app_key(): void
    {
        $application = $this->invite('key-token');
        $this->submit('key-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));

        $stored = Storage::disk('documents')->get(data_get($application->fresh()->payload, 'document_scan_path'));

        // A stolen disk without the key is bytes. This is the whole point of the vault.
        $otherKey = new Encrypter(random_bytes(32), config('app.cipher'));

        $this->expectException(DecryptException::class);
        $otherKey->decryptString($stored);
    }

    // --- OPTIONAL means optional ----------------------------------------------------------------------

    public function test_an_application_with_no_upload_succeeds(): void
    {
        $application = $this->invite('no-upload-token');

        $this->submit('no-upload-token', $this->humanFields())->assertRedirect();

        $fresh = $application->fresh();
        $this->assertNotNull($fresh->submitted_at, 'the application did not submit without an upload');
        $this->assertSame('María', data_get($fresh->payload, 'first_name'));
        $this->assertNull(data_get($fresh->payload, 'document_scan_path'));
    }

    public function test_no_validation_rule_makes_the_upload_required(): void
    {
        $rules = (new SubmitApplicationRequest)->rules();

        $this->assertContains('nullable', $rules['document_scan']);
        $this->assertNotContains('required', $rules['document_scan']);
    }

    // --- refused before storage, with a readable reason -----------------------------------------------

    public function test_an_oversize_file_is_refused_before_storage(): void
    {
        $application = $this->invite('big-token');
        $tooBig = DocumentUpload::maxKilobytes() + 1024;

        $this->submit('big-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->create('dni.pdf', $tooBig, 'application/pdf'),
        ]))->assertSessionHasErrors('document_scan');

        $this->assertNull(data_get($application->fresh()->payload, 'document_scan_path'));
    }

    public function test_a_wrong_type_is_refused_before_storage(): void
    {
        $application = $this->invite('type-token');

        $this->submit('type-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ]))->assertSessionHasErrors('document_scan');

        $this->assertNull(data_get($application->fresh()->payload, 'document_scan_path'));
        $this->assertEmpty(Storage::disk('documents')->files('member-id-scans'));
    }

    public function test_the_refusal_message_is_a_sentence_not_a_validation_key(): void
    {
        $messages = (new SubmitApplicationRequest)->messages();

        $this->assertArrayHasKey('document_scan.max', $messages);
        $this->assertStringContainsString(DocumentUpload::limitLabel(), $messages['document_scan.max']);
        $this->assertArrayHasKey('document_scan.mimes', $messages);
    }

    // --- the unauthenticated route is rate limited ----------------------------------------------------

    public function test_file_bearing_submissions_are_rate_limited(): void
    {
        // Five an hour per IP: generous for a person, tight for a disk. The sixth still SUBMITS — an upload
        // is optional, so losing it must never cost someone their application — but nothing is stored.
        for ($i = 1; $i <= 5; $i++) {
            $application = $this->invite("rl-token-$i");
            $this->submit("rl-token-$i", $this->humanFields([
                'document_scan' => UploadedFile::fake()->image("dni-$i.jpg"),
            ]));
            $this->assertNotNull(data_get($application->fresh()->payload, 'document_scan_path'), "upload $i should have stored");
        }

        $sixth = $this->invite('rl-token-6');
        $this->submit('rl-token-6', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni-6.jpg'),
        ]))->assertRedirect();

        $fresh = $sixth->fresh();
        $this->assertNotNull($fresh->submitted_at, 'the application must still submit over the upload limit');
        $this->assertNull(data_get($fresh->payload, 'document_scan_path'), 'the 6th upload should not have stored');
    }

    public function test_the_route_itself_still_carries_its_throttle(): void
    {
        // The uploads are not a separate endpoint — they ride this POST, so its limit applies to them too.
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->getName() === 'socio.application.store'
        );

        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    // --- retention: an application nobody approved does not keep it -----------------------------------

    public function test_an_abandoned_applications_scan_is_deleted_by_the_sweep(): void
    {
        $application = $this->invite('abandoned-token');
        $this->submit('abandoned-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));

        $path = data_get($application->fresh()->payload, 'document_scan_path');
        Storage::disk('documents')->assertExists($path);

        $this->travel(400)->days();
        $this->artisan('applications:prune-retention')->assertSuccessful();

        Storage::disk('documents')->assertMissing($path);
        $this->assertNull($application->fresh()->payload);
    }

    public function test_a_rejected_applications_scan_is_deleted_by_the_sweep(): void
    {
        $application = $this->invite('rejected-token');
        $this->submit('rejected-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));
        $path = data_get($application->fresh()->payload, 'document_scan_path');

        $application->fresh()->update(['status' => ApplicationStatus::REJECTED]);

        $this->travel(400)->days();
        $this->artisan('applications:prune-retention')->assertSuccessful();

        Storage::disk('documents')->assertMissing($path);
    }

    public function test_a_live_applications_scan_is_not_deleted(): void
    {
        $application = $this->invite('live-token');
        $this->submit('live-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));
        $path = data_get($application->fresh()->payload, 'document_scan_path');

        // Inside retention: the sweep must not touch it.
        $this->artisan('applications:prune-retention')->assertSuccessful();

        Storage::disk('documents')->assertExists($path);
        $this->assertNotNull($application->fresh()->payload);
    }

    public function test_the_sweep_is_idempotent_and_logs_what_it_deleted(): void
    {
        $application = $this->invite('idem-token');
        $this->submit('idem-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));
        $path = data_get($application->fresh()->payload, 'document_scan_path');

        $this->travel(400)->days();
        $this->artisan('applications:prune-retention')->assertSuccessful();

        // A silent deletion of Article 9 material is as bad as an indefinite retention of it.
        $log = AuditLog::query()->where('action', PruneApplications::ACTION)
            ->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, data_get($log->after, 'id_scans_deleted'));

        // Running it again is a no-op: nothing left to scrub, no second audit row for the same work.
        $this->artisan('applications:prune-retention')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()
            ->where('action', PruneApplications::ACTION)->count());
        Storage::disk('documents')->assertMissing($path);
    }

    // --- approval carries the SAME object, not a copy --------------------------------------------------

    public function test_an_approved_applications_scan_becomes_the_members_scan(): void
    {
        $application = $this->invite('approve-token');
        $this->submit('approve-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));
        $path = data_get($application->fresh()->payload, 'document_scan_path');

        $member = (new ApproveApplication)->handle($application->fresh(), User::factory()->create()->id);

        // The SAME object, not a copy — one file, one path, so the two can never diverge.
        $this->assertSame($path, $member->document_scan_path);
        Storage::disk('documents')->assertExists($member->document_scan_path);
    }

    public function test_an_approved_applications_scan_survives_the_sweep(): void
    {
        $application = $this->invite('survive-token');
        $this->submit('survive-token', $this->humanFields([
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));
        $path = data_get($application->fresh()->payload, 'document_scan_path');
        (new ApproveApplication)->handle($application->fresh(), User::factory()->create()->id);

        $this->travel(400)->days();
        $this->artisan('applications:prune-retention')->assertSuccessful();

        // Deleting it would blank a real member's document — approved rows are never swept.
        Storage::disk('documents')->assertExists($path);
    }

    // --- the face photo is a different artefact --------------------------------------------------------

    public function test_the_photo_and_the_id_are_stored_separately_and_never_merged(): void
    {
        $application = $this->invite('two-token');

        $this->submit('two-token', $this->humanFields([
            'photo' => UploadedFile::fake()->image('face.jpg'),
            'document_scan' => UploadedFile::fake()->image('dni.jpg'),
        ]));

        $payload = $application->fresh()->payload;
        $photo = data_get($payload, 'photo_path');
        $scan = data_get($payload, 'document_scan_path');

        $this->assertNotNull($photo);
        $this->assertNotNull($scan);
        $this->assertNotSame($photo, $scan, 'a face and an identity document must not be one artefact');
        $this->assertStringStartsWith('member-photos/', $photo);
        $this->assertStringStartsWith('member-id-scans/', $scan);
    }

    // --- the form says what happens to it, in both locales ---------------------------------------------

    public function test_the_form_shows_the_upload_its_limit_and_what_happens_to_the_file(): void
    {
        $this->invite('copy-token');

        $label = 'Documento de identidad (opcional)';
        $help = 'Foto o PDF de tu DNI, NIE o pasaporte. Se guarda cifrado, solo se abre con un enlace firmado y cada consulta queda registrada. Si tu solicitud no se aprueba, se borra. Puedes omitirlo y enseñarlo en el mostrador.';

        foreach (['es', 'en'] as $locale) {
            // The applicant's own switcher (prompt 167) drops an in-session override, which SetLocale reads —
            // driving that is the only honest way to render this route in a chosen locale. `app()->setLocale()`
            // is overwritten by the middleware on the way in, and asserting with a bare `__()` afterwards then
            // compares the response's locale against itself, which passes without testing anything.
            $response = $this->withSession(['locale' => $locale])
                ->get(route('socio.application', ['token' => 'copy-token']));

            $response->assertOk();
            $response->assertSee('name="document_scan"', false);
            $response->assertSee(trans($label, [], $locale));
            // The ceiling, before they pick a file (prompt 164), and the transparency sentence.
            $response->assertSee(DocumentUpload::limitLabel());
            $response->assertSee(trans($help, [], $locale));
        }

        // …and the two locales really are different text, so neither assertion above can pass vacuously.
        $this->assertNotSame(trans($help, [], 'es'), trans($help, [], 'en'));
    }

    public function test_the_upload_field_is_not_marked_required_on_the_form(): void
    {
        $this->invite('mark-token');

        $html = $this->get(route('socio.application', ['token' => 'mark-token']))->getContent();
        $field = substr($html, strpos($html, 'name="document_scan"'), 200);

        $this->assertStringNotContainsString('required', $field);
    }
}
