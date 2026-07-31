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
 */
class ApplicationSpamGuard
{
    /** A genuine human needs at least this long to read + complete the form. */
    public const MIN_SECONDS = 3;

    /** The honeypot field name — plausible enough that a bot fills it, ignored by the payload. */
    public const HONEYPOT = 'website';

    /** The hidden field carrying the signed render time. */
    public const TIMESTAMP = 'form_started_at';

    /** A fresh signed render timestamp to embed in the form (encrypted, so it can't be forged). */
    public static function issueToken(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
    }

    /**
     * True when the submission carries automation fingerprints — a filled honeypot,
     * or a render token that is missing, unreadable/tampered, or impossibly recent.
     * The caller treats a true result as "silently discard", never as a user error.
     */
    public static function looksAutomated(Request $request): bool
    {
        // 1. Honeypot — only something filling every field completes this hidden one.
        if (filled($request->input(self::HONEYPOT))) {
            return true;
        }

        // 2. Minimum submit time — decode the signed render time; a bad token is itself a signal.
        try {
            $renderedAt = (int) Crypt::decryptString((string) $request->input(self::TIMESTAMP));
        } catch (Throwable) {
            return true;
        }

        // Faster than a human could plausibly fill the form (also catches a future-dated forgery).
        return (now()->timestamp - $renderedAt) < self::MIN_SECONDS;
    }
}
