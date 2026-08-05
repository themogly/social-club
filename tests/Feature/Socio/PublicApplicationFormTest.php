<?php

namespace Tests\Feature\Socio;

use App\Actions\Members\ApproveApplication;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Models\ConsentRecord;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\ConsentText;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 97 — the public application form (the first thing a prospect sees). It asked for a birthday in a
 * US format on a Spanish form, took consent to text nobody was shown, and asked for two fields an applicant
 * cannot fill. These pin the fixes: unambiguous DOB, informed + version-tied consent, a guided consumption
 * choice, a sponsor by name, a next-steps promise, and the Spanish default — without regressing the
 * token-gate, honeypot or rate limit.
 */
class PublicApplicationFormTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
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

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'declared_monthly_g' => '30', 'consent_data' => '1', 'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            // A render token aged past the minimum submit time, so a genuine submit isn't taken for a bot.
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

    public function test_date_of_birth_is_captured_unambiguously_in_a_spanish_context(): void
    {
        app()->setLocale('es');
        $app = $this->invite('t');

        // "4 March 1990" — the native date input submits ISO, so 4 March cannot be read as 3 April.
        $this->post(route('socio.application.store', ['token' => 't']), $this->formData(['date_of_birth' => '1990-03-04']));

        $this->assertSame('1990-03-04', $app->fresh()->payload['date_of_birth']);

        $member = (new ApproveApplication)->handle($app->fresh());
        $this->assertSame('04/03/1990', $member->date_of_birth->format('d/m/Y')); // 4 March, not 3 April
    }

    public function test_an_underage_applicant_is_rejected_entering_the_date_in_the_forms_format(): void
    {
        app()->setLocale('es');
        $app = $this->invite('t');

        $this->post(route('socio.application.store', ['token' => 't']), $this->formData([
            'date_of_birth' => now()->subYears(16)->format('Y-m-d'), // the form's own ISO format
        ]))->assertSessionHasErrors('date_of_birth');

        $this->assertSame([], $app->fresh()->payload); // nothing stored
    }

    public function test_the_consent_texts_are_shown_and_the_recorded_version_matches_the_displayed_one(): void
    {
        app()->setLocale('es');
        $this->invite('t');

        // The texts (and their version) are ON the page — consent cannot be informed otherwise. Default locale
        // is es (prompt 96), so the authoritative Spanish declarations render (prompt 153).
        $this->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertSee(ConsentText::privacy('es'))
            ->assertSee(ConsentText::statutes('es'))
            ->assertSee('1.0');

        // Submit at v1.0, then a later revision bumps the version…
        $app = MemberApplication::query()->firstOrFail();
        $this->post(route('socio.application.store', ['token' => 't']), $this->formData());
        Settings::set('consent_text_version', '2.0');

        // …the recorded version is the one the applicant SAW (1.0), never the later 2.0.
        $member = (new ApproveApplication)->handle($app->fresh());
        $versions = ConsentRecord::query()->where('member_id', $member->id)->pluck('consent_text_version')->unique();
        $this->assertSame(['1.0'], $versions->values()->all());
    }

    public function test_submitting_without_either_consent_is_refused(): void
    {
        $this->invite('t');

        $this->post(route('socio.application.store', ['token' => 't']), $this->formData(['consent_data' => null]))
            ->assertSessionHasErrors('consent_data');
        $this->post(route('socio.application.store', ['token' => 't']), $this->formData(['consent_statutes' => null]))
            ->assertSessionHasErrors('consent_statutes');
    }

    public function test_the_honeypot_and_a_bad_token_still_guard_the_form(): void
    {
        $app = $this->invite('good');

        // Filled honeypot → silently discarded (identical redirect, nothing stored).
        $this->post(route('socio.application.store', ['token' => 'good']), $this->formData([ApplicationSpamGuard::HONEYPOT => 'bot']))
            ->assertRedirect();
        $this->assertSame([], $app->fresh()->payload);

        // A bad token still 404s.
        $this->get(route('socio.application', ['token' => 'nope']))->assertNotFound();
        $this->post(route('socio.application.store', ['token' => 'nope']), $this->formData())->assertNotFound();
    }

    public function test_the_form_renders_in_the_club_default_language(): void
    {
        // A prospect cannot have a preference, so the ONLY lever is the club default — now Spanish (prompt 96).
        $this->invite('t');

        $this->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Solicitud de alta')       // Spanish, not "Membership application"
            ->assertSee('Qué ocurre después:');     // the next-steps promise
    }

    public function test_an_applicant_without_a_sponsor_is_not_blocked(): void
    {
        $app = $this->invite('t');

        $this->post(route('socio.application.store', ['token' => 't']), $this->formData(['avalador_ref' => '']))
            ->assertRedirect();

        $fresh = $app->fresh();
        $this->assertNotNull($fresh->payload['first_name']);         // submitted successfully
        $this->assertNotNull($fresh->submitted_at);
        $this->assertNull($fresh->payload['avalador_member_id']);    // no sponsor, no block
    }

    public function test_a_sponsor_is_resolved_by_name_or_by_number(): void
    {
        $sponsor = Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Juan', 'last_name' => 'Pérez',
            'member_no' => 'M-00042', 'status' => MemberStatus::ACTIVE,
        ]);

        $byName = $this->invite('a');
        $this->post(route('socio.application.store', ['token' => 'a']), $this->formData(['avalador_ref' => 'Juan Pérez']));
        $this->assertSame($sponsor->id, $byName->fresh()->payload['avalador_member_id']);

        $byNumber = $this->invite('b');
        $this->post(route('socio.application.store', ['token' => 'b']), $this->formData(['avalador_ref' => 'M-00042']));
        $this->assertSame($sponsor->id, $byNumber->fresh()->payload['avalador_member_id']);
    }

    public function test_declared_consumption_comes_from_the_guided_options_and_is_stored_as_centigrams(): void
    {
        $app = $this->invite('t');

        $this->post(route('socio.application.store', ['token' => 't']), $this->formData(['declared_monthly_g' => '50']));

        $this->assertSame(5000, $app->fresh()->payload['declared_monthly_cg']); // 50 g → 5000 cg
    }
}
