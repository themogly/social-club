<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prompt 348 — "Aaron signed up a user and it didn't save": every sign-up path writes what HAPPENED, never what was typed —
 * the application's id, which route (link / handover / staff), the outcome, the validation KEYS that failed, an error's
 * class. Read back by `php artisan csc:signup-trace`. A logging failure never breaks a sign-up.
 */
class SignupTrace
{
    /** @param  array<string, mixed>  $context  ids, keys and classes only */
    public static function record(string $event, array $context = []): void
    {
        try {
            Log::channel('signup')->info($event, $context + ['operator_id' => CounterOperator::id()]);
        } catch (Throwable) {
            // best-effort: the trace must never be the reason a sign-up fails
        }
    }
}
