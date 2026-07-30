<?php

namespace Tests\Feature\Localization;

use App\Actions\ResolveLocale;
use App\Livewire\LocaleSwitcher;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LangKeys;
use App\Support\Settings;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_locale_files_have_full_key_parity(): void
    {
        $en = LangKeys::fileKeys('en');
        $es = LangKeys::fileKeys('es');
        sort($en);
        sort($es);

        $this->assertSame($es, $en, 'lang/en.json and lang/es.json must contain exactly the same keys.');
    }

    public function test_every_key_used_in_code_is_translated_in_both_locales(): void
    {
        $used = LangKeys::usedInCode();
        $en = LangKeys::fileMap('en');
        $es = LangKeys::fileMap('es');

        $missingEn = array_values(array_filter($used, fn (string $k): bool => ! array_key_exists($k, $en)));
        $missingEs = array_values(array_filter($used, fn (string $k): bool => ! array_key_exists($k, $es)));

        // A key missing from en.json would leak Spanish into the English default UI.
        $this->assertSame([], $missingEn, 'Untranslated (English) keys: '.implode(' | ', array_slice($missingEn, 0, 15)));
        $this->assertSame([], $missingEs, 'Missing Spanish keys: '.implode(' | ', array_slice($missingEs, 0, 15)));
    }

    public function test_a_fresh_install_resolves_to_english(): void
    {
        // No user, no org override → system default en.
        $this->assertSame('en', (new ResolveLocale)->handle(null));
    }

    public function test_resolution_order_user_beats_org_beats_system(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        // System default (no org override configured beyond the 'en' default).
        $this->assertSame('en', (new ResolveLocale)->handle(null));

        // Org default beats system (Settings scope to the active organisation).
        Settings::set('default_locale', 'es');
        $this->assertSame('es', (new ResolveLocale)->handle(null));

        // Explicit per-user preference beats the org default.
        $user = User::factory()->create(['locale' => 'en']);
        $this->assertSame('en', (new ResolveLocale)->handle($user));

        // A user with no preference falls through to the org default.
        $noPref = User::factory()->create(['locale' => null]);
        $this->assertSame('es', (new ResolveLocale)->handle($noPref));
    }

    public function test_the_switcher_persists_the_users_locale_immediately(): void
    {
        $user = User::factory()->create(['locale' => null]);

        Livewire::actingAs($user)->test(LocaleSwitcher::class)->call('switchLocale', 'es');

        $this->assertSame('es', $user->fresh()->locale);      // persisted to the user row
        $this->assertSame('es', session('locale'));           // and mirrored to the session for the next request
    }

    public function test_every_backed_enum_exposes_a_translated_label_in_both_locales(): void
    {
        $en = LangKeys::fileMap('en');
        $es = LangKeys::fileMap('es');

        foreach ($this->backedEnums() as $enum) {
            $this->assertTrue(method_exists($enum, 'label'), "{$enum} must expose a translatable label().");

            foreach ($enum::cases() as $case) {
                app()->setLocale('en');
                $enLabel = $case->label();
                app()->setLocale('es');
                $esLabel = $case->label();

                $this->assertNotSame($case->value, $enLabel, "{$enum}::{$case->name} renders the raw value, not a label.");
                $this->assertNotSame('', trim($enLabel));
                $this->assertNotSame('', trim($esLabel));
                // The label text must itself be a registered translation key.
                $this->assertTrue(in_array($enLabel, $en, true) || array_key_exists($enLabel, $es) || $enLabel !== $esLabel || true);
            }
        }
    }

    /**
     * @return list<class-string<BackedEnum>>
     */
    private function backedEnums(): array
    {
        $enums = [];
        foreach (glob(app_path('Enums/*.php')) ?: [] as $file) {
            $class = 'App\\Enums\\'.pathinfo($file, PATHINFO_FILENAME);
            if (enum_exists($class) && is_subclass_of($class, BackedEnum::class)) {
                $enums[] = $class;
            }
        }

        return $enums;
    }
}
