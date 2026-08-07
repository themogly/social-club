<?php

namespace Tests\Browser;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 179 — writes the application form in its scan states for the screenshot pass
 * (`node tests/Browser/shoot-mrz-prefill.mjs`). Socio page, so app-*.css only, and the locale comes from
 * the session override (prompt 167's switcher) — see the README.
 */
class MrzPrefillHarnessTest extends TestCase
{
    use RefreshDatabase;

    private const TD3 = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n"
        .'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    public function test_it_writes_the_scan_states(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        MemberApplication::factory()->create([
            'organisation_id' => $org->id,
            'invite_token_hash' => hash('sha256', 'shot'),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);

        foreach (['es', 'en'] as $locale) {
            // 1) the scan step — nothing read yet, which is also the unsupported-browser state: an ordinary
            //    form. (The trigger is `hidden` until the script mounts, so a static capture shows exactly
            //    what a browser that cannot run the reader shows.)
            // flushSession(), not MrzPrefill::forget(): withSession() re-seeds from the stored session, so a
            // read performed for the previous locale would otherwise survive into this one's "plain" capture.
            $this->flushSession();
            $this->write('plain-'.$locale, $this->render($locale));

            // 2) a successful read: fields filled and every one marked unconfirmed.
            $this->post(route('socio.application.read', ['token' => 'shot']), ['mrz' => self::TD3]);
            $this->write('prefilled-'.$locale, $this->render($locale));

            // 3) a correction in progress — one field confirmed, one being retyped.
            $this->write('correcting-'.$locale, $this->render($locale, [
                'first_name' => 'ANNA MARIE',
                'mrz_confirmed' => ['last_name' => '1'],
            ]));
        }

        foreach (['plain-es', 'prefilled-es', 'correcting-es'] as $state) {
            $html = (string) file_get_contents(storage_path('app/mrz-'.$state.'.html'));
            $this->assertStringContainsString('data-mrz-scan', $html, "$state has no scan control");
            // The engine is never referenced from the page — it is a dynamic import inside the handler.
            $this->assertStringNotContainsString('/ocr/', $html);
        }
    }

    private function render(string $locale, array $old = []): string
    {
        $request = $this->withSession(['locale' => $locale]);

        if ($old !== []) {
            $request = $request->withSession(['locale' => $locale, '_old_input' => $old]);
        }

        return $request->get(route('socio.application', ['token' => 'shot']))->getContent();
    }

    private function write(string $name, string $html): void
    {
        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/mrz-'.$name.'.html'), $html);
    }
}
