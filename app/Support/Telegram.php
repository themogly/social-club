<?php

namespace App\Support;

use App\Exceptions\TelegramTokenRejectedException;
use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Prompt 311 — the Telegram Bot API, the three calls this app makes: send a plain-text message, register the webhook, and
 * the one-time `t.me` link a person opens to connect. Server-side only; the token never reaches a browser. With no token
 * configured, Telegram is off and nothing here is called.
 */
final class Telegram
{
    /** Prompt 363 — when Telegram last rejected the bot token (cache, forever until a good send clears it). */
    private const REJECTED = 'telegram.token_rejected_at';

    /** One Sentry report an hour while the token stays rejected, not one per message. */
    private const REPORTED = 'telegram.token_rejected_reported';

    public static function configured(): bool
    {
        return filled(config('services.telegram.token')) && filled(config('services.telegram.username'));
    }

    public static function sendMessage(string $chatId, string $text): Response
    {
        // Prompt 324 — nothing leaves for something done in *Modo formación* (the queue is off too; this is the backstop).
        if (TrainingMode::running()) {
            return new Response(new Psr7Response(503));
        }

        return Http::asJson()->timeout(10)
            ->post(self::endpoint('sendMessage'), ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
    }

    /** Prompt 363 — the token's real check: who the bot is (`telegram:check`, and before `telegram:set-webhook`). */
    public static function getMe(): Response
    {
        return Http::timeout(10)->get(self::endpoint('getMe'));
    }

    /** 401 (wrong or revoked token) and 404 (a malformed one) are configuration, never a passing outage. */
    public static function rejectsToken(int $status): bool
    {
        return $status === 401 || $status === 404;
    }

    /** Flag the token as rejected for Salud del sistema, and tell Sentry — at most once an hour. */
    public static function markTokenRejected(int $status): void
    {
        Cache::forever(self::REJECTED, now()->timestamp);
        if (Cache::add(self::REPORTED, true, now()->addHour())) {
            report(new TelegramTokenRejectedException($status));
        }
    }

    public static function clearTokenRejected(): void
    {
        Cache::forget(self::REJECTED);
        Cache::forget(self::REPORTED);
    }

    public static function tokenRejectedAt(): ?CarbonInterface
    {
        $at = Cache::get(self::REJECTED);

        return is_int($at) ? Carbon::createFromTimestamp($at) : null;
    }

    public static function setWebhook(string $url): Response
    {
        return Http::asJson()->timeout(10)->post(self::endpoint('setWebhook'), [
            'url' => $url,
            'secret_token' => (string) config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message'],
        ]);
    }

    /** The link a person opens on their phone and taps *Start* on. */
    public static function startLink(string $code): string
    {
        return 'https://t.me/'.ltrim((string) config('services.telegram.username'), '@').'?start='.$code;
    }

    private static function endpoint(string $method): string
    {
        // An empty TELEGRAM_API_URL= line is '' (not the default): fall back explicitly.
        $base = (string) config('services.telegram.api_url') ?: 'https://api.telegram.org';

        return rtrim($base, '/').'/bot'.config('services.telegram.token').'/'.$method;
    }
}
