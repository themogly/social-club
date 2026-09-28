<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Post-296 audit, finding 7 — the owner's decision: a PIN session (a registered tablet plus a PIN, prompts 267/289) may
 * open the panel only after the person gives their password (and their MFA code where enrolled), once per SHIFT per
 * tablet. A password login is never asked: it already did exactly that. The counter itself stays PIN-only.
 */
final class PanelIdentity
{
    public const SHIFT_HOURS = 12;

    public static function mustConfirm(User $user): bool
    {
        if (session('auth.via_pin') !== true) {
            return false;
        }

        try {
            return ! Cache::has(self::key($user));
        } catch (Throwable) {
            return true; // fail closed: when in doubt, ask for the password
        }
    }

    public static function confirmed(User $user): void
    {
        Cache::put(self::key($user), true, now()->addHours(self::SHIFT_HOURS));
    }

    /** Per person, per tablet: someone else on the same tablet — or the same person on another — is asked for theirs. */
    private static function key(User $user): string
    {
        $terminal = session('counter.terminal_id');

        return 'panel-identity:'.$user->id.':'.(is_string($terminal) ? $terminal : 'browser');
    }
}
