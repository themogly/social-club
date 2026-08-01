<?php

namespace App\Actions\Members;

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
    public function handle(Member $member, string $purpose = 'membership', ?string $ip = null, ?string $version = null, ?CarbonInterface $grantedAt = null): ConsentRecord
    {
        return $member->consents()->create([
            'purpose' => $purpose,
            'consent_text_version' => $version ?? (string) Settings::get('consent_text_version', '1.0'),
            'granted_at' => $grantedAt ?? now(),
            'ip' => $ip,
        ]);
    }
}
