<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterHandover;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prompt 362 — the spam guard's minimum-time check restarted at every render, so an applicant who fixed one field after a
 * refusal (their photos kept since 361) and tapped Enviar again within 3 s saw «¡Gracias!» and was never registered. The
 * clock now starts at the FIRST render; a token that already failed once, or a handover, skips the timing check; the
 * honeypot always applies; every silent discard is logged.
 */
class SpamGuardAfterAFormErrorTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

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
        $this->freezeSecond();
    }

    private function invite(string $token): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id, 'invite_token_hash' => hash('sha256', $token),
            'status' => ApplicationStatus::PENDING, 'payload' => [], 'location_id' => $this->sede->id,
        ]);
    }

    /** Render the form as a browser would, and read the signed render time it embeds. */
    private function render(string $token): string
    {
        $html = $this->get(route('socio.application', ['token' => $token]))->assertOk()->getContent();
        preg_match('/name="'.ApplicationSpamGuard::TIMESTAMP.'" value="([^"]+)"/', (string) $html, $m);

        return html_entity_decode($m[1] ?? '');
    }

    private function fields(string $formToken, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'consent_data' => '1', 'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            'photo' => UploadedFile::fake()->image('foto.jpg', 40, 40),
            ApplicationSpamGuard::HONEYPOT => '', ApplicationSpamGuard::TIMESTAMP => $formToken,
        ], $overrides);
    }

    private function submit(string $token, array $fields): TestResponse
    {
        return $this->post(route('socio.application.store', ['token' => $token]), $fields);
    }

    // 1 — fixing one field after a refusal, quickly, still registers the applicant.
    public function test_a_quick_resubmit_after_a_form_error_is_stored(): void
    {
        $application = $this->invite('fix');
        $first = $this->render('fix');
        $this->travel(5)->seconds();
        $this->submit('fix', $this->fields($first, ['document_number' => '']))->assertSessionHasErrors('document_number');

        $redisplayed = $this->render('fix'); // the page after the error
        $this->submit('fix', $this->fields($redisplayed, ['photo' => null])); // under a second later

        $fresh = $application->fresh();
        $this->assertNotNull($fresh->submitted_at, 'the applicant saw «¡Gracias!» and was not registered');
        $this->assertNotNull(data_get($fresh->payload, 'photo_path'), 'the kept photo was used');
    }

    // 2 — a first submission faster than a person could read is still discarded silently, and now logged.
    public function test_a_first_impossibly_fast_submission_is_discarded_and_logged(): void
    {
        Log::spy();
        $application = $this->invite('fast');
        $this->submit('fast', $this->fields($this->render('fast')))->assertRedirect();

        $this->assertNull($application->fresh()->submitted_at);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'application.discarded'
            && $context['signal'] === 'timing' && $context['application_id'] === $application->id)->once();
    }

    // 3 — the honeypot applies everywhere: after a refusal, and during a handover.
    public function test_a_filled_honeypot_is_discarded_even_after_a_refusal_and_on_the_tablet(): void
    {
        $application = $this->invite('honey');
        $first = $this->render('honey');
        $this->travel(5)->seconds();
        $this->submit('honey', $this->fields($first, ['document_number' => '']));
        $this->submit('honey', $this->fields($this->render('honey'), ['photo' => null, ApplicationSpamGuard::HONEYPOT => 'http://spam.example']));
        $this->assertNull($application->fresh()->submitted_at, 'after a refusal');

        $tablet = $this->invite('honey-tablet');
        $this->handover('honey-tablet');
        $this->travel(5)->seconds();
        $this->submit('honey-tablet', $this->fields($this->render('honey-tablet'), [ApplicationSpamGuard::HONEYPOT => 'x']));
        $this->assertNull($tablet->fresh()->submitted_at, 'during a handover');
    }

    // 4 — the club's tablet: staff are there, the timing check does not apply.
    public function test_a_quick_handover_submission_is_stored(): void
    {
        $application = $this->invite('tablet');
        $this->handover('tablet');
        $this->submit('tablet', $this->fields($this->render('tablet')));

        $this->assertNotNull($application->fresh()->submitted_at);
    }

    // 5 — a forged render time is still automation.
    public function test_a_future_or_unreadable_render_time_is_still_automated(): void
    {
        $future = $this->invite('future');
        $this->render('future');
        $this->travel(10)->seconds();
        $this->submit('future', $this->fields(Crypt::encryptString((string) now()->addHour()->timestamp)));
        $this->assertNull($future->fresh()->submitted_at);

        $garbage = $this->invite('garbage');
        $this->render('garbage');
        $this->travel(10)->seconds();
        $this->submit('garbage', $this->fields('not-a-token'));
        $this->assertNull($garbage->fresh()->submitted_at);
    }

    private function handover(string $token): void
    {
        $staff = User::factory()->create(['pin' => '3456']);
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->sede->id]);
        CounterHandover::begin($staff->id, $this->sede->id, route('socio.application', ['token' => $token]));
    }
}
