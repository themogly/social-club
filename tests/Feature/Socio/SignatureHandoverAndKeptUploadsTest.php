<?php

namespace Tests\Feature\Socio;

use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Mail\ApplicationInviteMail;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\DocumentVault;
use App\Support\KeptUploads;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 361 — from Ben's video at the club: a drawn signature didn't count until «Guardar firma»; a tablet that came back
 * unsigned ended the sign-up; a server-side error threw the photos away; and the invitation's button didn't work in the
 * member's mail app. (The canvas half of the signature fix is proved in the browser: tests/Browser/prove-361-*.mjs.)
 */
class SignatureHandoverAndKeptUploadsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        RateLimiter::clear('application-upload:127.0.0.1');
        Storage::fake('documents');
        $this->staff = User::factory()->create(['pin' => '3456']);
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->sede->id]);
    }

    private function invite(string $rawToken): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id, 'invite_token_hash' => hash('sha256', $rawToken),
            'status' => ApplicationStatus::PENDING, 'payload' => [], 'location_id' => $this->sede->id,
        ]);
    }

    private function fields(array $overrides = []): array
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $timestamp = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'consent_data' => '1', 'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            'photo' => UploadedFile::fake()->image('foto.jpg', 40, 40),
            ApplicationSpamGuard::HONEYPOT => '', ApplicationSpamGuard::TIMESTAMP => $timestamp,
        ], $overrides);
    }

    private function submit(string $token, array $fields): TestResponse
    {
        return $this->post(route('socio.application.store', ['token' => $token]), $fields);
    }

    private function handover(string $token): void
    {
        CounterHandover::begin($this->staff->id, $this->sede->id, route('socio.application', ['token' => $token]));
    }

    // --- 2, 4. Unsigned: refused on the link, allowed (once confirmed) on the club's tablet ----------------------------------

    public function test_an_untouched_pad_on_the_link_is_still_refused(): void
    {
        $application = $this->invite('link-blank');
        $this->submit('link-blank', $this->fields(['signature' => '', 'confirm_unsigned' => '1']))->assertSessionHasErrors('signature');
        $this->assertNull($application->fresh()->submitted_at);
    }

    public function test_on_the_clubs_tablet_an_unsigned_form_goes_through_once_confirmed_and_is_marked(): void
    {
        $application = $this->invite('handover-blank');
        $this->handover('handover-blank');

        $this->submit('handover-blank', $this->fields(['signature' => '']))->assertSessionHasErrors('signature'); // not confirmed
        $this->submit('handover-blank', $this->fields(['signature' => '', 'confirm_unsigned' => '1']))->assertSessionHasNoErrors();

        $fresh = $application->fresh();
        $this->assertNotNull($fresh->submitted_at);
        $this->assertTrue((bool) $fresh->signature_missing);
        $this->assertNull(data_get($fresh->payload, 'signature_path'));
    }

    // --- 5–6. Back at the counter --------------------------------------------------------------------------------------------

    private function unsignedSubmitted(): MemberApplication
    {
        $application = $this->invite('back');
        $this->handover('back');
        $this->submit('back', $this->fields(['signature' => '', 'confirm_unsigned' => '1']));
        CounterHandover::end();

        return $application->fresh();
    }

    private function counterReview(MemberApplication $application, ?User $operator = null): Testable
    {
        $operator ??= $this->staff;
        $this->actingAs($operator);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($operator);

        return Livewire::test(MembershipCounter::class)->set('altaOpen', true)->call('reviewAltaApplication', $application->id);
    }

    public function test_the_review_says_falta_la_firma_and_firmar_ahora_attaches_one(): void
    {
        $application = $this->unsignedSubmitted();
        $review = $this->counterReview($application)->assertSee(__('Falta la firma'))
            ->assertSeeHtml('data-missing-signature')->assertSeeHtml('data-sign-now')->assertSeeHtml('data-sign-waive');

        $review->call('captureApplicationSignature', 'data:image/png;base64,'.base64_encode('drawn at the counter'));
        $fresh = $application->fresh();
        $this->assertFalse((bool) $fresh->signature_missing);
        $this->assertSame('drawn at the counter', DocumentVault::get((string) data_get($fresh->payload, 'signature_path')));
    }

    public function test_seguir_sin_firma_with_a_reason_lets_the_approval_through_audited_and_shown(): void
    {
        $application = $this->unsignedSubmitted();
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $review = $this->counterReview($application)->set('altaTierId', $tier->id);

        $review->call('approveAlta')->assertSet('flashType', 'error'); // 6 — the server refuses with no override
        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->status);

        $review->call('waiveApplicationSignature', 'TABLET')->call('approveAlta');
        $fresh = $application->fresh();
        $this->assertSame(ApplicationStatus::APPROVED, $fresh->status);
        $this->assertSame([$this->staff->id, __('La tableta no funcionaba')], [$fresh->signature_override_by, $fresh->signature_override_reason]);
        $this->assertTrue(AuditLog::query()->where('action', 'application.signature_waived')->exists());

        $member = Member::query()->withoutGlobalScopes()->find($fresh->resulting_member_id);
        $this->assertNotNull($member);
        $this->assertSame(__('Sin firma digital — autorizado por :name', ['name' => $this->staff->name]), $member->signatureWaiverLabel());
    }

    // --- 7. Email: the full link as text under the button ------------------------------------------------------------------------

    public function test_every_single_action_email_carries_its_link_as_plain_text(): void
    {
        $url = 'https://dg.padron.app/socio/solicitud/'.str_repeat('a', 40);
        $html = (new ApplicationInviteMail($url, '2026-10-30'))->render();
        $this->assertStringContainsString(__('¿El botón no funciona? Copia y pega este enlace en tu navegador:'), $html);
        $this->assertMatchesRegularExpression('/data-plain-link[^>]*>\s*'.preg_quote(e($url), '/').'\s*</', $html);

        // The plain-text part already carried the URL as its only content line — kept that way.
        $this->assertStringContainsString($url, view('mail.text.application-invite', ['url' => $url, 'expiresOn' => '2026-10-30'])->render());

        foreach (File::files(resource_path('views/mail')) as $file) {
            $source = $file->getContents();
            if (substr_count($source, 'href=') === 1) { // one link = one action
                $this->assertStringContainsString('mail.partials.plain-link', $source, $file->getFilename().' has one button and no plain link');
            }
        }
    }

    // --- 8–11. Uploads survive a refused submit ---------------------------------------------------------------------------------

    public function test_photos_survive_a_refused_submit_and_are_used_without_reattaching(): void
    {
        $application = $this->invite('keep');
        $photo = UploadedFile::fake()->image('foto.jpg', 40, 40);
        $scan = UploadedFile::fake()->image('dni.jpg', 60, 40);
        $photoBytes = (string) file_get_contents($photo->getRealPath());
        $scanBytes = (string) file_get_contents($scan->getRealPath());

        // A server-side refusal: the ID number the server rejects.
        $this->submit('keep', $this->fields(['photo' => $photo, 'document_scan' => $scan, 'document_number' => '']))->assertSessionHasErrors('document_number');
        $this->get(route('socio.application', ['token' => 'keep']))->assertOk()
            ->assertSee(__('✓ Foto guardada'))->assertSeeHtml('data-kept-upload="photo"')->assertSeeHtml('data-kept-upload="document_scan"');

        $this->submit('keep', $this->fields(['photo' => null, 'document_scan' => null]))->assertSessionHasNoErrors();
        $payload = $application->fresh()->payload;
        $this->assertSame($photoBytes, DocumentVault::get((string) data_get($payload, 'photo_path')));
        $this->assertSame($scanBytes, DocumentVault::get((string) data_get($payload, 'document_scan_path')));
        $this->assertSame([], KeptUploads::for('keep'), 'the kept copies are gone once used');
    }

    public function test_a_new_photo_replaces_the_kept_one_and_a_missing_signature_keeps_them_too(): void
    {
        $application = $this->invite('replace');
        $this->submit('replace', $this->fields(['signature' => '']))->assertSessionHasErrors('signature'); // the action's refusal
        $this->assertArrayHasKey('photo', KeptUploads::for('replace'));

        $newer = UploadedFile::fake()->image('otra.jpg', 80, 80);
        $bytes = (string) file_get_contents($newer->getRealPath());
        $this->submit('replace', $this->fields(['photo' => $newer]))->assertSessionHasNoErrors();
        $this->assertSame($bytes, DocumentVault::get((string) data_get($application->fresh()->payload, 'photo_path')));
    }

    public function test_kept_files_are_cleaned_up_and_never_shown_to_another_session(): void
    {
        $this->invite('clean');
        $this->submit('clean', $this->fields(['document_number' => '']));
        $kept = KeptUploads::for('clean');
        $this->assertArrayHasKey('photo', $kept);

        // 11 — another browser on the same link sees nothing.
        $this->flushSession();
        $this->assertSame([], KeptUploads::for('clean'));
        $this->get(route('socio.application', ['token' => 'clean']))->assertDontSee(__('✓ Foto guardada'));

        // 10 — the 24-hour purge removes anything left behind.
        Storage::disk('documents')->assertExists($kept['photo']);
        $this->travel(25)->hours();
        Artisan::call('applications:purge-kept-uploads');
        Storage::disk('documents')->assertMissing($kept['photo']);
    }

    public function test_salir_sin_enviar_and_expiry_discard_kept_files(): void
    {
        $this->invite('exit');
        $this->handover('exit');
        $this->submit('exit', $this->fields(['document_number' => '']));
        $path = KeptUploads::for('exit')['photo'];
        $this->post(route('socio.application.staff', ['token' => 'exit']), ['pin' => '3456']);
        Storage::disk('documents')->assertMissing($path);

        $expiring = $this->invite('expire');
        $this->submit('expire', $this->fields(['document_number' => '']));
        $path = KeptUploads::for('expire')['photo'];
        $expiring->update(['invite_expires_at' => now()->subMinute()]);
        Artisan::call('applications:purge-kept-uploads');
        Storage::disk('documents')->assertMissing($path);
    }
}
