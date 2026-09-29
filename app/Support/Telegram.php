<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Prompt 311 — the Telegram Bot API, the three calls this app makes: send a plain-text message, register the webhook, and
 * the one-time `t.me` link a person opens to connect. Server-side only; the token never reaches a browser. With no token
 * configured, Telegram is off and nothing here is called.
 */
final class Telegram
{
    public static function configured(): bool
    {
        return filled(config('services.telegram.token')) && filled(config('services.telegram.username'));
    }

    public static function sendMessage(string $chatId, string $text): Response
    {
        return Http::asJson()->timeout(10)
            ->post(self::endpoint('sendMessage'), ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
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
