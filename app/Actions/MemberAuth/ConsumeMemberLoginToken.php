<?php

namespace App\Actions\MemberAuth;

use App\Models\Member;
use App\Models\MemberLoginToken;
use Illuminate\Support\Facades\DB;

/**
 * Consume a magic-link token: valid exactly once, before it expires. Marks the token
 * used inside a locked transaction so a double-click (or a shared link) cannot log in
 * twice. Returns the member on success, null otherwise (unknown/expired/already used).
 */
class ConsumeMemberLoginToken
{
    public function handle(string $token): ?Member
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash): ?Member {
            $record = MemberLoginToken::query()->where('token_hash', $hash)->lockForUpdate()->first();

            if ($record === null || $record->used_at !== null || $record->expires_at->isPast()) {
                return null;
            }

            $record->update(['used_at' => now()]);

            return Member::query()->withoutGlobalScopes()->find($record->member_id);
        });
    }
}
