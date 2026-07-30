<?php

namespace App\Actions\Members;

use App\Models\ConsentRecord;
use App\Models\Member;
use App\Support\Settings;

/**
 * Capture an explicit, versioned RGPD consent as its own row — consent history is
 * never a scalar flag (mirrors the application path in ApproveApplication). The
 * version is the one in force at capture time (`consent_text_version`), so a later
 * text revision never rewrites what the member actually agreed to.
 */
class RecordMemberConsent
{
    public function handle(Member $member, string $purpose = 'membership', ?string $ip = null): ConsentRecord
    {
        return $member->consents()->create([
            'purpose' => $purpose,
            'consent_text_version' => (string) Settings::get('consent_text_version', '1.0'),
            'granted_at' => now(),
            'ip' => $ip,
        ]);
    }
}
