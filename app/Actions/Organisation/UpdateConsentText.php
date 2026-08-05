<?php

namespace App\Actions\Organisation;

use App\Actions\RecordAuditLog;
use App\Enums\SettingType;
use App\Exceptions\ConsentVersionRequiredException;
use App\Support\ConsentText;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer for the two org-wide consent declarations (prompt 153 built the editor; prompt 159 makes it
 * reproducible). It enforces the two rules that make consent text safe to edit:
 *
 *  1. A CHANGED text under the SAME version is REFUSED (ConsentVersionRequiredException) — reusing the version
 *     would silently rewrite what already-consented members are recorded as having read.
 *  2. On a bump, the OUTGOING version's text is ARCHIVED before the new text is written, so
 *     `ConsentText::privacyForVersion()`/`statutesForVersion()` can still resolve exactly what an older
 *     record's member saw. Without this, prompt 153's version number pointed at text that had moved on.
 *
 * @phpstan-type LocaleMap array<string, string>
 */
class UpdateConsentText
{
    /**
     * @param  array<string, string>  $privacy  locale => text
     * @param  array<string, string>  $statutes  locale => text
     */
    public function handle(array $privacy, array $statutes, string $newVersion): void
    {
        $currentVersion = ConsentText::version();
        $newVersion = trim($newVersion) !== '' ? trim($newVersion) : $currentVersion;

        $old = ConsentText::editable();
        $oldPrivacy = $old['consent_privacy_text'];
        $oldStatutes = $old['consent_statutes_text'];
        $newPrivacy = $this->clean($privacy);
        $newStatutes = $this->clean($statutes);

        $changed = $newPrivacy !== $oldPrivacy || $newStatutes !== $oldStatutes;

        if ($changed && $newVersion === $currentVersion) {
            throw new ConsentVersionRequiredException($currentVersion);
        }

        DB::transaction(function () use ($changed, $currentVersion, $newVersion, $oldPrivacy, $oldStatutes, $newPrivacy, $newStatutes): void {
            $before = [
                'consent_text_version' => $currentVersion,
                'consent_privacy_text' => $oldPrivacy,
                'consent_statutes_text' => $oldStatutes,
            ];

            if ($changed) {
                $archive = Settings::get(ConsentText::ARCHIVE_KEY, []);
                $archive = is_array($archive) ? $archive : [];
                // Preserve the outgoing version's text (only if not already archived — first bump captures 1.0).
                $archive[$currentVersion] ??= [
                    'consent_privacy_text' => $oldPrivacy,
                    'consent_statutes_text' => $oldStatutes,
                ];
                $archive[$newVersion] = [
                    'consent_privacy_text' => $newPrivacy,
                    'consent_statutes_text' => $newStatutes,
                ];

                Settings::set(ConsentText::ARCHIVE_KEY, $archive, SettingType::JSON);
                Settings::set('consent_privacy_text', $newPrivacy, SettingType::JSON);
                Settings::set('consent_statutes_text', $newStatutes, SettingType::JSON);
                Settings::set('consent_text_version', $newVersion, SettingType::STRING);
            } elseif ($newVersion !== $currentVersion) {
                Settings::set('consent_text_version', $newVersion, SettingType::STRING);
            }

            (new RecordAuditLog)->handle('consent_text.updated', null, $before, [
                'consent_text_version' => $newVersion,
                'consent_privacy_text' => $newPrivacy,
                'consent_statutes_text' => $newStatutes,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    private function clean(array $texts): array
    {
        $out = [];
        foreach ($texts as $locale => $text) {
            $trimmed = trim((string) $text);
            if ($trimmed !== '') {
                $out[(string) $locale] = $trimmed;
            }
        }

        return $out;
    }
}
