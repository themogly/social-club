<?php

namespace App\Console\Commands;

use App\Support\LangKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keep the locale files honest (prompt 19). Generates `lang/es.json` from the keys actually used in
 * code (the Spanish source string maps to itself), preserving existing entries, and REPORTS the keys
 * still missing an English translation in `lang/en.json` — so a human/agent fills in real English,
 * never a blank or a machine guess.
 *
 * `--check` is a non-writing report: non-zero exit if either locale is missing a used key, or if the
 * two files hold different key SETS. It is a developer convenience, NOT a CI gate — the gate is
 * `tests/Feature/Localization/LocalizationTest`, which runs inside `composer check`. The docblock used
 * to claim otherwise (prompt 171).
 *
 * Both files are written in ONE canonical order — case-insensitive, ties broken by the raw key — so a
 * run adds the new keys and moves nothing else. It used to `ksort()` (byte order) while the committed
 * files were in case-insensitive order, so every run produced ~230 lines of pure reordering and buried
 * the actual change. `en.json`'s VALUES are never touched: only its ordering is normalised, so the
 * command can never invent English.
 */
class LangSync extends Command
{
    protected $signature = 'lang:sync {--check : Report drift and exit non-zero without writing}';

    protected $description = 'Sync lang/es.json to the keys used in code and report untranslated English keys';

    public function handle(): int
    {
        $used = LangKeys::usedInCode();
        $es = LangKeys::fileMap('es');
        $en = LangKeys::fileMap('en');

        $missingEs = array_values(array_diff($used, array_keys($es)));
        $missingEn = array_values(array_diff($used, array_keys($en)));

        // Key SETS, compared in both directions. The old check compared array_keys() with `==`, which
        // on two lists is ORDER-SENSITIVE — so a clean repo whose files held identical keys in
        // different orders reported "missing es: 0 · missing en: 0" and then exited 1, saying nothing
        // about why. Order is not a translation defect and must not fail anything; this now agrees
        // with LocalizationTest, which sorts before comparing.
        $onlyInEn = array_values(array_diff(array_keys($en), array_keys($es)));
        $onlyInEs = array_values(array_diff(array_keys($es), array_keys($en)));

        if ($this->option('check')) {
            $ok = $missingEs === [] && $missingEn === [] && $onlyInEn === [] && $onlyInEs === [];

            $this->line(sprintf('Keys used: %d · missing es: %d · missing en: %d', count($used), count($missingEs), count($missingEn)));

            foreach (array_slice($missingEn, 0, 30) as $key) {
                $this->warn("  no English: {$key}");
            }
            foreach (array_slice($missingEs, 0, 30) as $key) {
                $this->warn("  no Spanish: {$key}");
            }
            foreach (array_slice($onlyInEn, 0, 30) as $key) {
                $this->warn("  in en.json only: {$key}");
            }
            foreach (array_slice($onlyInEs, 0, 30) as $key) {
                $this->warn("  in es.json only: {$key}");
            }

            if (! $ok) {
                $this->error('Locale files are out of sync — see the keys above.');
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        // es.json = identity for every used key (Spanish source → Spanish), keeping manual overrides.
        foreach ($used as $key) {
            $es[$key] ??= $key;
        }

        $this->write('es', $es);
        // en.json is REORDERED but never added to: a placeholder here would satisfy the parity check
        // while shipping Spanish to an English reader, which is the exact leak prompt 19 gates against.
        $this->write('en', $en);

        $this->info(sprintf('Wrote lang/es.json (%d keys). %d key(s) still need English in lang/en.json.', count($es), count($missingEn)));

        if ($onlyInEn !== [] || $onlyInEs !== []) {
            $this->warn(sprintf('%d key(s) exist in only one locale — run lang:sync --check for the list.', count($onlyInEn) + count($onlyInEs)));
        }

        return self::SUCCESS;
    }

    /**
     * The canonical on-disk order, shared by both files: case-insensitive, ties broken by the raw key
     * so it is deterministic and stable. Matches how the files have actually been maintained by hand,
     * which is why adopting it costs one small normalisation instead of a 230-line reshuffle on every
     * run.
     *
     * @param  array<string, string>  $map
     */
    private function write(string $locale, array $map): void
    {
        uksort($map, fn (string $a, string $b): int => [mb_strtolower($a), $a] <=> [mb_strtolower($b), $b]);

        File::put(
            lang_path("{$locale}.json"),
            json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }
}
