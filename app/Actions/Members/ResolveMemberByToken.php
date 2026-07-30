<?php

namespace App\Actions\Members;

use App\Models\Member;
use App\Models\MemberToken;

/** Resolve a member from a scanned QR-card token (the primary check-in/counter lookup). */
class ResolveMemberByToken
{
    public function handle(string $token): ?Member
    {
        $record = MemberToken::query()
            ->where('purpose', 'QR_CARD')
            ->whereNull('revoked_at')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $record?->member;
    }
}
