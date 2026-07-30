<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The one source of truth for translation-key coverage (prompt 19). Scans the
 * codebase for every static `__('…')` / `@lang('…')` key, and reads the two JSON
 * locale files, so both the `lang:sync` command and the completeness test work off
 * identical data. Keys are the Spanish source strings; `lang/es.json` maps them to
 * Spanish (identity) and `lang/en.json` to English — the app default is `en`, so a
 * key missing from en.json would leak Spanish, which is exactly what the test forbids.
 */
class LangKeys
{
    /**
     * Every statically-resolvable translation key used in app/ and resources/.
     *
     * @return list<string>
     */
    public static function usedInCode(): array
    {
        $keys = [];
        // First quoted argument of __() or @lang(), single OR double quoted.
        $pattern = '/(?:__|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/';

        foreach (self::sourceFiles() as $file) {
            $contents = (string) File::get($file);
            if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = stripcslashes($m[2]);
                    // Skip dotted keys (PHP array files) and interpolated/empty keys.
                    if ($key !== '' && ! str_contains($key, '$') && ! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key)) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        ksort($keys);

        return array_keys($keys);
    }

    /**
     * The keys defined in a locale's JSON file.
     *
     * @return list<string>
     */
    public static function fileKeys(string $locale): array
    {
        return array_keys(self::fileMap($locale));
    }

    /**
     * @return array<string, string>
     */
    public static function fileMap(string $locale): array
    {
        $path = lang_path("{$locale}.json");
        if (! File::exists($path)) {
            return [];
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) File::get($path), true) ?: [];

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $files = [];
        foreach ([app_path(), resource_path('views'), lang_path()] as $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                if (in_array($file->getExtension(), ['php', 'blade.php'], true) || str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
