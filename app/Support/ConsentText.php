<?php

namespace App\Support;

/**
 * The two Article 7 consent declarations, per-locale and versioned AS A SET (prompt 153). Every read of them
 * goes through here, so the locale fallback and the "Spanish is authoritative" position live in ONE place: an
 * applicant reading in English sees the English of the current version, but the club — a Spanish asociación with
 * Spanish estatutos — authors the Spanish, and any locale with no text falls back to it, never to blank. The
 * version AND the locale actually read are both stamped on the consent record (RecordMemberConsent), so an
 * inspection asking "what did this member agree to, and in which language?" has an answer (prompt 97).
 */
class ConsentText
{
    /** The authoritative language: the club is a Spanish asociación; the others are translations of a versioned Spanish text. */
    public const AUTHORITATIVE = 'es';

    public static function privacy(?string $locale = null): string
    {
        return self::resolve('consent_privacy_text', $locale);
    }

    public static function statutes(?string $locale = null): string
    {
        return self::resolve('consent_statutes_text', $locale);
    }

    public static function version(): string
    {
        return (string) Settings::get('consent_text_version', '1.0');
    }

    /** True when the applicant is reading the authoritative Spanish — used to hide the "this is a translation" notice. */
    public static function isAuthoritative(?string $locale = null): bool
    {
        return ($locale ?? app()->getLocale()) === self::AUTHORITATIVE;
    }

    /**
     * The text for a locale, falling back to the authoritative Spanish, then to '' — a setting read never throws
     * and a missing locale degrades to the Spanish, never to a blank declaration. A LEGACY value stored as a
     * single string (from before per-locale storage, or a stale row) is read as the Spanish text.
     */
    private static function resolve(string $key, ?string $locale): string
    {
        $locale ??= app()->getLocale();
        $texts = self::asLocaleMap($key);

        return $texts[$locale] ?? $texts[self::AUTHORITATIVE] ?? '';
    }

    /**
     * Both texts for the editor, as `[key => [locale => text]]`, so the settings screen edits the whole set.
     *
     * @return array<string, array<string, string>>
     */
    public static function editable(): array
    {
        return [
            'consent_privacy_text' => self::asLocaleMap('consent_privacy_text'),
            'consent_statutes_text' => self::asLocaleMap('consent_statutes_text'),
        ];
    }

    /** @return array<string, string> */
    private static function asLocaleMap(string $key): array
    {
        $texts = Settings::get($key, []);

        if (is_string($texts)) {
            $texts = [self::AUTHORITATIVE => $texts];   // legacy single-string value = the Spanish declaration
        }

        return is_array($texts) ? array_map(fn ($v): string => (string) $v, $texts) : [];
    }
}
