<?php

namespace App\Support;

/**
 * Normalisation for till terminal names (prompt 84). The terminal string KEYS a till session, so a typo
 * used to open a phantom till the POS then couldn't find. `clean()` is the stored/display form (trimmed,
 * whitespace collapsed); `key()` is the comparison form (lower-case, alphanumeric only) so "POS 1", "POS-1"
 * and "pos-1" collapse to one terminal and cannot become two tills by accident.
 */
class TerminalName
{
    /** The stored/display form: trimmed, internal whitespace collapsed to a single space. */
    public static function clean(string $raw): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
    }

    /** The comparison key: lower-case, alphanumeric only — separators and case do not distinguish terminals. */
    public static function key(string $raw): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(self::clean($raw)));
    }

    /**
     * Add $terminal to a configured list if no existing entry shares its key. Returns the new list, with
     * the cleaned name. Idempotent — re-adding "pos-1" when "POS 1" is present is a no-op.
     *
     * @param  list<string>  $existing
     * @return list<string>
     */
    public static function register(array $existing, string $terminal): array
    {
        $clean = self::clean($terminal);
        if ($clean === '') {
            return $existing;
        }

        $key = self::key($clean);
        foreach ($existing as $name) {
            if (self::key($name) === $key) {
                return $existing; // already configured (in some spelling)
            }
        }

        $existing[] = $clean;

        return $existing;
    }
}
