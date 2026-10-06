<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Spam mitigation for the ONE unauthenticated member-facing form (the tokenised
 * invite → pre-registration). Layered on top of the existing rate limit, and
 * deliberately SILENT: a tripped signal never surfaces an error to the client, so
 * an automated submitter learns nothing about why its rows never appear.
 *
 * Two cheap signals, no third-party dependency:
 *  - a honeypot field, hidden from humans but filled by naive form-fillers;
 *  - a minimum submit time, proven by a signed render timestamp — a form completed
 *    faster than a person could read it is almost certainly scripted.
 *
 * Prompt 362 — the timing check silently threw away a REAL sign-up: the render time was re-issued on the redisplay after a
 * form error, and since 361 kept the photos an applicant could fix one field and resubmit within 3 s, see «¡Gracias!» and
 * never be registered. Now:
 *  - the clock starts at the FIRST render of this token's form in this session ({@see issueToken()});
 *  - a token that already failed once in this session (or has kept uploads) skips the ELAPSED-time check, and so does
 *    a handover (the applicant is at the counter, signed in through staff);
 *  - the honeypot and the token's own validity (it must decrypt and not be in the future) always apply;
 *  - every silent discard is logged with the signal that fired ({@see ApplicationController::store()}).
 */
class ApplicationSpamGuard
{
    /** A genuine human needs at least this long to read + complete the form. */
    public const MIN_SECONDS = 3;

    /** The honeypot field name — plausible enough that a bot fills it, ignored by the payload. */
    public const HONEYPOT = 'website';

    /** The hidden field carrying the signed render time. */
    public const TIMESTAMP = 'form_started_at';

    private const FIRST_RENDER = 'application_first_render';

    private const FAILED = 'application_failed_attempt';

    /**
     * A signed render timestamp to embed in the form (encrypted, so it can't be forged). With the invite `$token`, it is the
     * FIRST render of that form in this session (prompt 362): a redisplay after a refusal carries the original clock.
     */
    public static function issueToken(?string $token = null): string
    {
        if ($token === null) {
            return Crypt::encryptString((string) now()->timestamp);
        }
        $key = self::FIRST_RENDER.'.'.self::tokenKey($token);
        $first = session($key);
        if (! is_int($first) || $first > now()->timestamp) {
            $first = now()->timestamp;
            session([$key => $first]);
        }

        return Crypt::encryptString((string) $first);
    }

    /** Prompt 362 — this token's form was refused once in this session: the applicant is real; the clock no longer applies. */
    public static function recordFailedAttempt(string $token): void
    {
        session([self::FAILED.'.'.self::tokenKey($token) => true]);
    }

    /**
     * True when the submission carries automation fingerprints. The caller treats a true result as "silently discard",
     * never as a user error.
     */
    public static function looksAutomated(Request $request, ?string $token = null, bool $handover = false): bool
    {
        return self::signal($request, $token, $handover) !== null;
    }

    /**
     * Which check fired — `honeypot`, `token` (missing, unreadable or future-dated) or `timing` (impossibly recent) — or null
     * for a genuine submission. The honeypot and the token always apply; the elapsed time is skipped during a handover and
     * once this token has failed in this session (a refusal, or kept uploads).
     */
    public static function signal(Request $request, ?string $token = null, bool $handover = false): ?string
    {
        // 1. Honeypot — only something filling every field completes this hidden one.
        if (filled($request->input(self::HONEYPOT))) {
            return 'honeypot';
        }

        // 2. The signed render time — a bad or future-dated token is itself a signal, in every case.
        try {
            $renderedAt = (int) Crypt::decryptString((string) $request->input(self::TIMESTAMP));
        } catch (Throwable) {
            return 'token';
        }
        if ($renderedAt > now()->timestamp) {
            return 'token';
        }

        // 3. Minimum submit time — not on the club's tablet, and not for a form already refused once (a real person).
        if ($handover || ($token !== null && (session(self::FAILED.'.'.self::tokenKey($token)) === true || KeptUploads::for($token) !== []))) {
            return null;
        }

        return (now()->timestamp - $renderedAt) < self::MIN_SECONDS ? 'timing' : null;
    }

    private static function tokenKey(string $token): string
    {
        return substr(hash('sha256', $token), 0, 32);
    }
}
