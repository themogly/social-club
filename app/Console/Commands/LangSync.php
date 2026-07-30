<?php

namespace App\Console\Commands;

use App\Support\LangKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keep the locale files honest (prompt 19). Generates lang/es.json from the keys
 * actually used in code (the Spanish source string maps to itself), preserving any
 * existing entries, and REPORTS the keys still missing an English translation in
 * lang/en.json — so a human/agent fills real English, never a blank or a guess.
 * `--check` makes it a non-writing gate: non-zero exit if es.json is out of sync or
 * any key lacks an English entry (used by the completeness test / CI).
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
        $parityGap = array_values(array_diff(array_keys($en), array_keys($es)) + array_diff(array_keys($es), array_keys($en)));

        if ($this->option('check')) {
            $ok = $missingEs === [] && $missingEn === [] && array_keys($en) == array_keys($es);
            $this->line(sprintf('Keys used: %d · missing es: %d · missing en: %d', count($used), count($missingEs), count($missingEn)));
            foreach (array_slice($missingEn, 0, 30) as $k) {
                $this->warn("  no English: {$k}");
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        // Write es.json = identity for every used key (Spanish source → Spanish), keeping
        // any manual overrides already present.
        foreach ($used as $key) {
            $es[$key] ??= $key;
        }
        ksort($es);
        File::put(lang_path('es.json'), json_encode($es, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        $this->info(sprintf('Wrote lang/es.json (%d keys). %d keys still need English in lang/en.json.', count($es), count($missingEn)));
        if (count($parityGap) > 0) {
            $this->warn(sprintf('%d key(s) differ between en.json and es.json — run the completeness test for the list.', count($parityGap)));
        }

        return self::SUCCESS;
    }
}
