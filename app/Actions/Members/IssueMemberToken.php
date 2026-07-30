<?php

namespace App\Actions\Members;

use App\Models\Member;
use App\Models\MemberToken;
use Illuminate\Support\Str;

/**
 * Issue a QR-card token: a non-guessable random string, NOT derived from the
 * member id (NOTES §B). Only the hash is stored; the plaintext is returned once
 * to encode in the QR/email. Issuing revokes the member's previous card, so a
 * regenerated card invalidates the old one.
 */
class IssueMemberToken
{
    public function handle(Member $member): string
    {
        $member->tokens()
            ->where('purpose', 'QR_CARD')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = Str::random(48);

        MemberToken::create([
            'member_id' => $member->id,
            'token_hash' => hash('sha256', $token),
            'purpose' => 'QR_CARD',
            'issued_at' => now(),
        ]);

        return $token;
    }
}
