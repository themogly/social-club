<?php

namespace Tests\Feature\Localization;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Prompt 169 — `lang/` held only `en.json` and `es.json`, neither carrying a single `validation.*`
 * key, and `.env.example` ships `APP_LOCALE=es` **with** `APP_FALLBACK_LOCALE=es` — so Laravel's own
 * bundled English file was never consulted either. Every validation failure in the Spanish product
 * rendered a raw key:
 *
 *     locale=es fallback=es  ->  validation.required / validation.email / validation.accepted
 *
 * The worst surface was the join form a prospect opens on their phone: an applicant who did not tick
 * the statutes box was told `validation.accepted`, on an Article 9 consent control, with no member of
 * staff beside them.
 *
 * Prompt 168 made this urgent rather than latent: panel-wide `novalidate` means every required field
 * now round-trips to the server, so this became the NORMAL failure path of every form in the panel.
 */
class ValidationMessageTest extends TestCase
{
    use RefreshDatabase;

    /** Every rule the application actually uses, per the prompt — enumerated, not spot-checked. */
    private const RULES_IN_USE = [
        'required' => ['field' => []],
        'email' => ['field' => 'not-an-email'],
        'numeric' => ['field' => 'abc'],
        'integer' => ['field' => 'abc'],
        'string' => ['field' => ['x']],
        'min:3' => ['field' => 'a'],
        'max:2' => ['field' => 'abcdef'],
        'date' => ['field' => 'not-a-date'],
        'before:today' => ['field' => '2999-01-01'],
        'after:today' => ['field' => '1999-01-01'],
        'accepted' => ['field' => '0'],
        'boolean' => ['field' => 'maybe'],
        'array' => ['field' => 'not-an-array'],
        'in:a,b' => ['field' => 'z'],
        'confirmed' => ['field' => 'x'],
        'same:other' => ['field' => 'x'],
        'different:field' => ['field' => 'x'],
        'digits:4' => ['field' => '12'],
        'url' => ['field' => 'nope'],
        'ulid' => ['field' => 'nope'],
        'uuid' => ['field' => 'nope'],
        'regex:/^\d+$/' => ['field' => 'abc'],
        'nullable|file' => ['field' => 'not-a-file'],
    ];

    // --- The guard --------------------------------------------------------------------------------

    public function test_no_validation_message_is_ever_a_raw_key_in_either_locale(): void
    {
        // Driven under the LIVE configuration: locale es with fallback es, which is what removed the
        // framework's English safety net and what .env.example ships.
        foreach (['es', 'en'] as $locale) {
            config(['app.locale' => $locale, 'app.fallback_locale' => $locale]);
            $this->app->setLocale($locale);

            foreach (self::RULES_IN_USE as $rule => $data) {
                $messages = Validator::make($data, ['field' => $rule])->errors()->all();

                $this->assertNotEmpty($messages, "Rule [{$rule}] produced no message to check.");

                foreach ($messages as $message) {
                    $this->assertStringNotContainsString('validation.', $message,
                        "Rule [{$rule}] rendered a raw key in [{$locale}]: {$message}");
                    $this->assertMatchesRegularExpression('/[a-záéíóúñ]/iu', $message,
                        "Rule [{$rule}] produced something that is not a sentence in [{$locale}]: {$message}");
                }
            }
        }
    }

    public function test_every_rule_in_use_has_a_spanish_line_of_its_own(): void
    {
        $es = require lang_path('es/validation.php');
        $en = require lang_path('en/validation.php');

        foreach (['required', 'email', 'numeric', 'integer', 'string', 'max', 'min', 'date', 'before',
            'accepted', 'boolean', 'image', 'mimes', 'array', 'password'] as $rule) {
            $this->assertArrayHasKey($rule, $es, "lang/es/validation.php has no line for [{$rule}].");
            $this->assertArrayHasKey($rule, $en, "lang/en/validation.php has no line for [{$rule}].");
        }

        // All four size variants, not just one.
        foreach (['max', 'min'] as $rule) {
            foreach (['array', 'file', 'numeric', 'string'] as $kind) {
                $this->assertArrayHasKey($kind, $es[$rule], "es max/min is missing the {$kind} variant.");
            }
        }
    }

    public function test_the_two_locale_files_cover_the_same_rules(): void
    {
        $es = array_keys(require lang_path('es/validation.php'));
        $en = array_keys(require lang_path('en/validation.php'));
        sort($es);
        sort($en);

        $this->assertSame($en, $es, 'lang/en/validation.php and lang/es/validation.php must cover the same rules.');
    }

    public function test_a_required_message_names_the_field_in_spanish(): void
    {
        config(['app.locale' => 'es', 'app.fallback_locale' => 'es']);
        $this->app->setLocale('es');

        $message = Validator::make([], ['document_number' => 'required'])->errors()->first();

        $this->assertSame('El campo número de documento es obligatorio.', $message);
    }

    public function test_auth_and_password_lines_are_translated_too(): void
    {
        // Published by the same command and broken by the same missing files — an unauthenticated
        // Spanish login otherwise reads "auth.failed".
        config(['app.locale' => 'es', 'app.fallback_locale' => 'es']);
        $this->app->setLocale('es');

        foreach (['auth.failed', 'auth.password', 'passwords.sent', 'passwords.token'] as $key) {
            $this->assertNotSame($key, __($key), "[{$key}] still renders as a raw key in Spanish.");
        }
    }

    // --- The surface that matters most ---------------------------------------------------------------

    public function test_the_applicant_reads_a_sentence_when_the_statutes_box_is_unticked(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        MemberApplication::factory()->create([
            'organisation_id' => $org->id,
            'invite_token_hash' => hash('sha256', 't'),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);

        config(['app.locale' => 'es', 'app.fallback_locale' => 'es']);
        $this->app->setLocale('es');

        $this->withSession(['locale' => 'es'])
            ->post(route('socio.application.store', ['token' => 't']), $this->formData(['consent_statutes' => null]))
            ->assertSessionHasErrors('consent_statutes');

        $message = (string) session('errors')->first('consent_statutes');

        $this->assertStringNotContainsString('validation.', $message);
        $this->assertStringContainsString('aceptación de los estatutos', $message);
    }

    public function test_the_same_failure_reads_in_english_for_an_applicant_who_switched(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        MemberApplication::factory()->create([
            'organisation_id' => $org->id,
            'invite_token_hash' => hash('sha256', 't'),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);

        // Prompt 167 made the switcher reachable on this screen; this is the other half of it working.
        $this->withSession(['locale' => 'en'])
            ->post(route('socio.application.store', ['token' => 't']), $this->formData(['consent_statutes' => null]))
            ->assertSessionHasErrors('consent_statutes');

        $message = (string) session('errors')->first('consent_statutes');

        $this->assertStringNotContainsString('validation.', $message);
        $this->assertStringContainsString('acceptance of the statutes', $message);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'declared_monthly_g' => '30', 'consent_data' => '1', 'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $token,
        ], $overrides);
    }
}
