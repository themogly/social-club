<?php

namespace App\Exceptions;

use App\Support\Telegram;
use RuntimeException;

/**
 * Prompt 363 — Telegram answered 401 (or 404, a malformed token): the BOT TOKEN is wrong or was revoked. Configuration, not
 * an outage. Reported to Sentry at most once an hour ({@see Telegram::markTokenRejected()}); the message names
 * the setting to fix and never the token itself.
 */
class TelegramTokenRejectedException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("Telegram rejected the bot token (HTTP {$status}) — check TELEGRAM_BOT_TOKEN, then php artisan config:cache and php artisan telegram:check");
    }
}
