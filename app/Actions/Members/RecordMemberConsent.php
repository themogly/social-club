<?php

namespace App\Actions\Members;

use App\Models\ConsentRecord;
use App\Models\Member;
use App\Support\Settings;

/**
 * Capture an explicit, versioned RGPD consent as its own row — consent history is
 * never a scalar flag (mirrors the application path in ApproveApplication). The
 * version defaults to the one in force at capture time (`consent_text_version`), so a
 * later text revision never rewrites what the member actually agreed to. A caller MAY
 * pass the version the person actually SAW (the public application captures it at
 * submit — prompt 97), so the stamped version is the displayed one, not a later one.
 */
class RecordMemberConsent
{
    public function handle(Member $member, string $purpose = 'membership', ?string $ip = null, ?string $version = null): ConsentRecord
    {
        return $member->consents()->create([
            'purpose' => $purpose,
            'consent_text_version' => $version ?? (string) Settings::get('consent_text_version', '1.0'),
            'granted_at' => now(),
            'ip' => $ip,
        ]);
    }
}
