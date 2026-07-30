<?php

namespace App\Actions\MemberAuth;

use App\Enums\MemberStatus;
use App\Mail\MemberLoginLinkMail;
use App\Models\Member;
use App\Models\MemberLoginToken;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Issue a passwordless magic link. Only the token HASH is stored; the raw token
 * travels solely in the emailed link. To avoid leaking which emails are members,
 * the caller always reports success regardless — this returns whether a link was
 * actually sent, for internal use/tests only. Short-lived and single-use (consumed
 * by ConsumeMemberLoginToken).
 */
class IssueMemberLoginLink
{
    public function handle(string $email, ?string $ip = null): bool
    {
        $member = Member::query()->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->where('status', MemberStatus::ACTIVE->value)
            ->first();

        if ($member === null) {
            return false;
        }

        $token = Str::random(64);
        $ttl = (int) Settings::get('member_login_link_ttl_minutes', 15);

        MemberLoginToken::create([
            'member_id' => $member->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttl),
            'requested_ip' => $ip,
        ]);

        Mail::to((string) $member->email)->queue(new MemberLoginLinkMail($member, $token));

        return true;
    }
}
