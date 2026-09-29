<?php

namespace App\Http\Controllers;

use App\Actions\Alerts\LinkTelegramChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prompt 311 — the bot's webhook. Refused unless Telegram's `X-Telegram-Bot-Api-Secret-Token` matches the secret it was
 * registered with (`telegram:set-webhook`); then the message goes to {@see LinkTelegramChat}. Outside the web group (no
 * session, no CSRF — Telegram has neither), throttled.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, LinkTelegramChat $link): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $chatId = $request->input('message.chat.id');
        $text = $request->input('message.text');
        if (is_scalar($chatId) && is_string($text)) {
            $link->handle((string) $chatId, $text);
        }

        return response()->json(['ok' => true]);
    }
}
