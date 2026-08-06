<?php

namespace Tests\Browser;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 178 — writes the public application form, in each locale, to storage/app/application-form-*.html
 * for the phone-width screenshot pass (`node tests/Browser/shoot-application-form.mjs`).
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check: the
 * optional ID upload is present, carries its size ceiling and its what-happens-to-it sentence, and is NOT
 * marked required — in both locales.
 */
class ApplicationFormHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_application_form_in_both_locales(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        MemberApplication::factory()->create([
            'organisation_id' => $org->id,
            'invite_token_hash' => hash('sha256', 'shot-token'),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);

        foreach (['es', 'en'] as $locale) {
            // The applicant's switcher (prompt 167) drops an in-session override that SetLocale reads. Setting
            // the app locale directly does nothing here — the middleware resolves it again on the way in.
            $html = $this->withSession(['locale' => $locale])
                ->get(route('socio.application', ['token' => 'shot-token']))->getContent();

            // Only app-*.css: the socio shell loads resources/css/app.css and nothing else. Globbing *.css
            // pulls in the Filament PANEL theme and corrupts the cascade (learned in prompt 176).
            $css = '';
            foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
                $css .= (string) file_get_contents($file);
            }

            if ($css !== '') {
                $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
                $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
            }

            file_put_contents(storage_path('app/application-form-'.$locale.'.html'), $html);

            $this->assertStringContainsString('name="document_scan"', $html, "$locale: no upload field");
            $this->assertStringContainsString(
                trans('Documento de identidad (opcional)', [], $locale), $html, "$locale: label not translated"
            );
            $this->assertStringContainsString('accept="image/*,application/pdf"', $html, "$locale: wrong accept");

            // Optional means optional, in the markup as well as the rules.
            $field = substr($html, strpos($html, 'name="document_scan"'), 200);
            $this->assertStringNotContainsString('required', $field, "$locale: the upload is marked required");
        }
    }
}
