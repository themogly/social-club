<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ApproveApplication;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Pages\ManageConsentText;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\ConsentText;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 153 — the consent declarations are per-locale, versioned as a set, and the locale the applicant read
 * is recorded alongside the version. An English applicant sees English declarations; a submission records which
 * language was read; prompt 97's "the version they saw" guarantee extends to the locale; and a club can edit
 * both languages behind its own permission.
 */
class ConsentTextPerLocaleTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    /** @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Mary', 'last_name' => 'Jones', 'email' => 'mary@example.test',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'consent_data' => '1', 'consent_statutes' => '1',
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

    public function test_the_form_shows_the_declaration_in_the_readers_language_and_switches_with_it(): void
    {
        $this->invite('t');
        $es = Settings::DEFAULTS['consent_privacy_text']['es'];
        $en = Settings::DEFAULTS['consent_privacy_text']['en'];

        // es — the authoritative Spanish, no "this is a translation" notice.
        $this->withSession(['locale' => 'es'])->get(route('socio.application', ['token' => 't']))
            ->assertOk()->assertSee($es)->assertDontSee($en)
            ->assertDontSee(__('La versión auténtica de estas declaraciones está en español; esta es una traducción de la versión :v.', ['v' => '1.0']));

        // en — the English translation, WITH the authoritative-Spanish notice. This is the reported bug.
        $this->withSession(['locale' => 'en'])->get(route('socio.application', ['token' => 't']))
            ->assertOk()->assertSee($en)->assertDontSee($es)
            ->assertSee(__('La versión auténtica de estas declaraciones está en español; esta es una traducción de la versión :v.', ['v' => '1.0']));
    }

    public function test_a_submission_records_the_locale_the_applicant_read_and_the_current_version(): void
    {
        // Distinct identities per locale so the second approval is not caught by the duplicate guard.
        $people = [
            'en' => ['email' => 'a@example.test', 'first_name' => 'Mary', 'last_name' => 'Jones', 'document_number' => '11111111H'],
            'es' => ['email' => 'b@example.test', 'first_name' => 'Marta', 'last_name' => 'Ruiz', 'document_number' => '22222222J'],
        ];
        foreach ($people as $locale => $data) {
            $token = 'tok-'.$locale;
            $app = $this->invite($token);
            $this->withSession(['locale' => $locale])
                ->post(route('socio.application.store', ['token' => $token]), $this->formData($data));

            $this->assertSame($locale, $app->fresh()->payload['consent_locale']);

            $member = (new ApproveApplication)->handle($app->fresh());
            foreach ($member->consents as $consent) {
                $this->assertSame($locale, $consent->locale);
                $this->assertSame('1.0', $consent->consent_text_version);
            }
        }
    }

    public function test_the_locale_and_version_recorded_are_those_seen_at_submit_not_a_later_revision(): void
    {
        // Prompt 97, extended to locale: submit in English at v1.0, THEN change both on the admin side.
        $app = $this->invite('t');
        $this->withSession(['locale' => 'en'])->post(route('socio.application.store', ['token' => 't']), $this->formData());
        Settings::set('consent_text_version', '2.0');
        app()->setLocale('es');

        $member = (new ApproveApplication)->handle($app->fresh());
        $consent = $member->consents()->firstOrFail();
        $this->assertSame('1.0', $consent->consent_text_version); // the version they saw, not 2.0
        $this->assertSame('en', $consent->locale);                // the language they read, not the current es
    }

    public function test_an_existing_consent_record_with_no_locale_reads_and_is_not_rewritten(): void
    {
        // A member who consented under 1.0 before this change: locale is genuinely absent, not Spanish.
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $consent = $member->consents()->create([
            'purpose' => 'membership', 'consent_text_version' => '1.0', 'locale' => null, 'granted_at' => now(),
        ]);

        $this->assertNull($consent->fresh()->locale, 'Absent means absent — never backfilled to a guess.');
        $this->assertSame('1.0', $consent->fresh()->consent_text_version);
    }

    public function test_a_club_can_edit_both_languages_of_both_texts_with_permission(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value); // OWNER holds settings.consent

        Livewire::actingAs($owner)->test(ManageConsentText::class)
            ->fillForm([
                'consent_text_version' => '1.1',
                'privacy_es' => 'Privacidad ES nueva', 'privacy_en' => 'Privacy EN new',
                'statutes_es' => 'Estatutos ES nuevos', 'statutes_en' => 'Statutes EN new',
            ])
            ->call('save')
            ->assertHasNoErrors();

        Settings::flush();
        $this->assertSame('Privacy EN new', ConsentText::privacy('en'));
        $this->assertSame('Privacidad ES nueva', ConsentText::privacy('es'));
        $this->assertSame('Statutes EN new', ConsentText::statutes('en'));
        $this->assertSame('1.1', ConsentText::version());
    }

    public function test_changing_a_text_without_bumping_the_version_is_refused(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        Livewire::actingAs($owner)->test(ManageConsentText::class)
            ->fillForm([
                'consent_text_version' => '1.0', // unchanged, but a text is edited below
                'privacy_es' => 'Un texto claramente distinto', 'privacy_en' => Settings::DEFAULTS['consent_privacy_text']['en'],
                'statutes_es' => Settings::DEFAULTS['consent_statutes_text']['es'], 'statutes_en' => Settings::DEFAULTS['consent_statutes_text']['en'],
            ])
            ->call('save');

        Settings::flush();
        // Refused: the stored text is unchanged and the version is still 1.0 — no silent rewrite.
        $this->assertSame(Settings::DEFAULTS['consent_privacy_text']['es'], ConsentText::privacy('es'));
        $this->assertSame('1.0', ConsentText::version());
    }

    public function test_only_a_user_with_settings_consent_can_reach_the_editor(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // MANAGER lacks settings.consent
        $this->actingAs($manager);
        $this->assertFalse(ManageConsentText::canAccess());

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);
        $this->assertTrue(ManageConsentText::canAccess());
    }
}
