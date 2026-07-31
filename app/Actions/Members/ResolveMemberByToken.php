<?php

namespace App\Actions\Members;

use App\Exceptions\ScanRateLimitedException;
use App\Models\Member;
use App\Models\MemberToken;
use App\Support\Settings;
use Illuminate\Support\Facades\RateLimiter;

/** Resolve a member from a scanned QR-card token (the primary check-in/counter lookup). */
class ResolveMemberByToken
{
    /**
     * @param  string|null  $throttleKey  when set, rate-limit FAILED scans under this key (the counter
     *                                    passes the operator/terminal). This Action is called from
     *                                    Livewire, so route middleware never applies — the throttle
     *                                    lives here (prompt 58).
     *
     * @throws ScanRateLimitedException when too many failed scans have occurred in the window
     */
    public function handle(string $token, ?string $throttleKey = null): ?Member
    {
        $key = $throttleKey !== null ? 'qr-scan:'.$throttleKey : null;
        $max = max(1, (int) Settings::get('qr_scan_max_failures_per_minute', 10));

        if ($key !== null && RateLimiter::tooManyAttempts($key, $max)) {
            throw new ScanRateLimitedException(RateLimiter::availableIn($key));
        }

        $record = MemberToken::query()
            ->where('purpose', 'QR_CARD')
            ->whereNull('revoked_at')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $member = $record?->member;

        // Only a MISS counts toward the limit — a busy counter of valid cards is never throttled, but a
        // scanner trying token after token to find a hit is stopped after $max failures per minute.
        if ($key !== null && $member === null) {
            RateLimiter::hit($key, 60);
        }

        return $member;
    }
}
