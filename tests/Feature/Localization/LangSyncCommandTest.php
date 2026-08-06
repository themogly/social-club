<?php

namespace Tests\Feature\Localization;

use App\Support\LangKeys;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Prompt 171 — the project's own locale gate failed on a clean repo and said nothing was wrong:
 *
 *     $ php artisan lang:sync --check
 *     Keys used: 1974 · missing es: 0 · missing en: 0
 *     $ echo $?
 *     1
 *
 * `array_keys()` returns a list and `==` on two lists is ORDER-SENSITIVE, so two files holding
 * identical key SETS in different orders failed the third condition. Meanwhile the writing mode
 * `ksort()`ed (byte order) while the committed files were in case-insensitive order, so every run
 * produced ~230 lines of pure reordering — which is why new keys had to be hand-added and the
 * command's output thrown away.
 *
 * The real gate is {@see LocalizationTest}, which sorts before comparing. These make the command
 * agree with it rather than the other way round.
 */
class LangSyncCommandTest extends TestCase
{
    private string $tmp;

    private string $originalLangPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Work on a COPY. These tests write locale files, and they must never be the repo's.
        $this->originalLangPath = lang_path();
        $this->tmp = storage_path('framework/testing/lang-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->tmp);
        File::copy($this->originalLangPath.'/en.json', $this->tmp.'/en.json');
        File::copy($this->originalLangPath.'/es.json', $this->tmp.'/es.json');
    }

    protected function tearDown(): void
    {
        $this->app->useLangPath($this->originalLangPath);
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    // --- The headline bug, asserted against the REAL repo files ---------------------------------

    public function test_check_exits_zero_on_a_clean_repo(): void
    {
        // Deliberately the real lang/ — --check never writes. This is the whole bug: it exited 1
        // while reporting nothing missing.
        $this->assertSame(0, Artisan::call('lang:sync', ['--check' => true]),
            "lang:sync --check must pass on a clean tree.\n".Artisan::output());
    }

    public function test_key_order_alone_never_fails_the_check(): void
    {
        $this->useTmpLang();

        $es = $this->read('es');
        $this->write('es', array_reverse($es, preserve_keys: true));

        $this->assertSame(0, Artisan::call('lang:sync', ['--check' => true]),
            'Key ORDER is not a translation defect and must not fail the gate.'."\n".Artisan::output());
    }

    // --- …but a genuine gap still fails, and says which key ---------------------------------------

    public function test_a_key_missing_from_es_fails_and_is_named(): void
    {
        $this->useTmpLang();

        $key = LangKeys::usedInCode()[0];
        $es = $this->read('es');
        unset($es[$key]);
        $this->write('es', $es);

        $this->assertSame(1, Artisan::call('lang:sync', ['--check' => true]));
        $this->assertStringContainsString($key, Artisan::output());
    }

    public function test_a_key_missing_from_en_fails_and_is_named(): void
    {
        $this->useTmpLang();

        $key = LangKeys::usedInCode()[0];
        $en = $this->read('en');
        unset($en[$key]);
        $this->write('en', $en);

        $this->assertSame(1, Artisan::call('lang:sync', ['--check' => true]));
        $this->assertStringContainsString($key, Artisan::output());
    }

    public function test_a_key_in_one_locale_only_fails_whatever_the_ordering(): void
    {
        $this->useTmpLang();

        $en = $this->read('en');
        $en['zzz clave que solo existe en ingles'] = 'only in en';
        $this->write('en', array_reverse($en, preserve_keys: true));   // and scrambled, to be sure

        $this->assertSame(1, Artisan::call('lang:sync', ['--check' => true]));
        $this->assertStringContainsString('zzz clave que solo existe en ingles', Artisan::output());
    }

    // --- The writing mode produces a reviewable diff ------------------------------------------------

    public function test_running_it_twice_leaves_the_second_run_with_nothing_to_do(): void
    {
        $this->useTmpLang();

        Artisan::call('lang:sync');
        $afterFirst = ['en' => $this->raw('en'), 'es' => $this->raw('es')];

        Artisan::call('lang:sync');

        $this->assertSame($afterFirst['es'], $this->raw('es'), 'lang:sync is not idempotent for es.json.');
        $this->assertSame($afterFirst['en'], $this->raw('en'), 'lang:sync is not idempotent for en.json.');
    }

    public function test_the_committed_files_are_already_in_the_canonical_order(): void
    {
        // i.e. running the command on a clean tree changes nothing at all — the property that makes a
        // new key's diff one line instead of one line buried in ~230.
        $this->useTmpLang();

        $before = ['en' => $this->raw('en'), 'es' => $this->raw('es')];

        Artisan::call('lang:sync');

        $this->assertSame($before['es'], $this->raw('es'));
        $this->assertSame($before['en'], $this->raw('en'));
    }

    public function test_a_missing_key_is_added_and_nothing_else_moves(): void
    {
        $this->useTmpLang();

        $key = LangKeys::usedInCode()[5];
        $es = $this->read('es');
        unset($es[$key]);
        $this->write('es', $es);
        $before = $this->read('es');

        Artisan::call('lang:sync');

        $after = $this->read('es');
        $this->assertArrayHasKey($key, $after);
        $this->assertSame($key, $after[$key], 'es.json maps a Spanish source key to itself.');

        // Every other entry is untouched — value AND presence.
        unset($after[$key]);
        $this->assertSame($before, $after, 'lang:sync moved or changed an entry other than the new key.');
    }

    public function test_english_is_never_invented(): void
    {
        // A placeholder would satisfy the parity check while shipping Spanish to an English reader,
        // which is the exact leak prompt 19 gates against. The command reports the gap instead.
        $this->useTmpLang();

        $key = LangKeys::usedInCode()[3];
        $en = $this->read('en');
        unset($en[$key]);
        $this->write('en', $en);

        Artisan::call('lang:sync');

        $this->assertArrayNotHasKey($key, $this->read('en'), 'lang:sync must never write an English value itself.');
    }

    public function test_no_run_ever_changes_a_translation_value(): void
    {
        $this->useTmpLang();

        $before = ['en' => $this->read('en'), 'es' => $this->read('es')];

        Artisan::call('lang:sync');

        foreach (['en', 'es'] as $locale) {
            $after = $this->read($locale);

            $beforeKeys = array_keys($before[$locale]);
            $afterKeys = array_keys($after);
            sort($beforeKeys);
            sort($afterKeys);
            $this->assertSame($beforeKeys, $afterKeys, "{$locale}.json key SET changed.");

            foreach ($before[$locale] as $key => $value) {
                $this->assertSame($value, $after[$key], "{$locale}.json changed the value of '{$key}'.");
            }
        }
    }

    public function test_the_key_sets_match_after_a_run(): void
    {
        $this->useTmpLang();

        $es = $this->read('es');
        unset($es[LangKeys::usedInCode()[7]]);
        $this->write('es', $es);

        Artisan::call('lang:sync');

        $en = array_keys($this->read('en'));
        $esKeys = array_keys($this->read('es'));
        sort($en);
        sort($esKeys);

        $this->assertSame($en, $esKeys);
    }

    // --- Fixtures ------------------------------------------------------------------------------------

    private function useTmpLang(): void
    {
        $this->app->useLangPath($this->tmp);
    }

    /** @return array<string, string> */
    private function read(string $locale): array
    {
        /** @var array<string, string> $decoded */
        $decoded = json_decode($this->raw($locale), true) ?: [];

        return $decoded;
    }

    private function raw(string $locale): string
    {
        return (string) File::get($this->tmp."/{$locale}.json");
    }

    /** @param array<string, string> $map */
    private function write(string $locale, array $map): void
    {
        File::put($this->tmp."/{$locale}.json", json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    }
}
