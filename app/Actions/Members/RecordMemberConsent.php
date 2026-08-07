<?php

namespace App\Actions\Members;

use App\Enums\ConsentChannel;
use App\Models\ConsentRecord;
use App\Models\Member;
use App\Support\Settings;
use Carbon\CarbonInterface;

/**
 * Capture an explicit, versioned RGPD consent as its own row — consent history is
 * never a scalar flag (mirrors the application path in ApproveApplication). The
 * version defaults to the one in force at capture time (`consent_text_version`), so a
 * later text revision never rewrites what the member actually agreed to. A caller MAY
 * pass the version the person actually SAW (the public application captures it at
 * submit — prompt 97), so the stamped version is the displayed one, not a later one.
 *
 * The paper-register import (prompt 131) is the one caller that passes BOTH an explicit
 * version and an explicit `$grantedAt`: it records the version the member signed on paper
 * and the date they signed it. It never lets the version default — importing a member as
 * having agreed to a text they never saw is worse than recording no consent at all — so
 * for an import this method is only reached when the CSV actually carried a version.
 */
class RecordMemberConsent
{
    public function handle(
        Member $member,
        string $purpose = 'membership',
        ?string $ip = null,
        ?string $version = null,
        ?CarbonInterface $grantedAt = null,
        ?string $locale = null,
        ConsentChannel $channel = ConsentChannel::APPLICANT,
        ?string $attestedBy = null,
    ): ConsentRecord {
        return $member->consents()->create([
            'purpose' => $purpose,
            'consent_text_version' => $version ?? (string) Settings::get('consent_text_version', '1.0'),
            // HOW it was captured, and who at the club recorded it if the club did (prompt 210). The default
            // is the applicant's own tick, which is what every consent meant before the staff-typed route
            // existed — so no existing caller changes meaning by not passing these.
            'channel' => $channel,
            'attested_by' => $channel->isApplicantsOwnAct() ? null : $attestedBy,
            // The locale the person actually READ the declaration in (prompt 153). Like the version, it is
            // whatever the caller captured at submit — NOT the current app locale. Left null when unknown (a
            // paper-register import, or a caller that did not observe one): absent means absent, never guessed.
            'locale' => $locale,
            'granted_at' => $grantedAt ?? now(),
            'ip' => $ip,
        ]);
    }
}
