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

    /**
     * The archive setting: every past consent-text version's text, kept so a member's consent record resolves
     * to the EXACT wording they agreed to even after the club bumps the version (prompt 159). Shape:
     * `{ "1.0": { consent_privacy_text: {es,en}, consent_statutes_text: {es,en} }, ... }`. The CURRENT version
     * is never read from here — it reads the live settings — so the archive only ever needs superseded versions.
     */
    public const ARCHIVE_KEY = 'consent_text_archive';

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
     * The privacy text a member agreed to under a SPECIFIC version (prompt 159). The current version reads the
     * live setting; a superseded version reads the archive, falling back to the authoritative Spanish of that
     * version and finally to the current live text — never blank. This is what lets an inspection answer "show
     * me exactly what THIS member consented to" after the wording has since moved on.
     */
    public static function privacyForVersion(string $version, ?string $locale = null): string
    {
        return self::resolveVersion('consent_privacy_text', $version, $locale);
    }

    public static function statutesForVersion(string $version, ?string $locale = null): string
    {
        return self::resolveVersion('consent_statutes_text', $version, $locale);
    }

    private static function resolveVersion(string $key, string $version, ?string $locale): string
    {
        $locale ??= app()->getLocale();

        if ($version === self::version()) {
            return self::resolve($key, $locale); // the current version is the live text
        }

        // Index the archive DIRECTLY, not via data_get: a version like "1.0" contains a dot, which data_get
        // would wrongly read as nesting (`['1']['0']`) and never find the entry.
        $archive = Settings::get(self::ARCHIVE_KEY, []);
        $versionTexts = is_array($archive) ? ($archive[$version] ?? []) : [];
        $archived = is_array($versionTexts) ? ($versionTexts[$key] ?? []) : [];
        $texts = is_array($archived) ? array_map(fn ($v): string => (string) $v, $archived) : [];

        return $texts[$locale] ?? $texts[self::AUTHORITATIVE] ?? self::resolve($key, $locale);
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
