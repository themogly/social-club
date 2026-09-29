<?php

namespace App\Actions\Alerts;

use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Jobs\SendTelegramMessage;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 311 — what the bot does with a message from the webhook: `/start <code>` links that chat to the person the code
 * was issued to (unexpired, unused — then used) and replies in their language; `/stop` unlinks whoever that chat was.
 * Anything else is ignored. The chat id is stored encrypted ({@see User::linkTelegram()}).
 */
class LinkTelegramChat
{
    public function handle(string $chatId, string $text): void
    {
        $text = trim($text);

        if ($text === '/stop') {
            $user = User::findByTelegramChat($chatId);
            if ($user !== null) {
                $user->unlinkTelegram();
                (new RecordAuditLog)->handle('alert.telegram_unlinked', $user);
            }

            return;
        }

        if (! str_starts_with($text, '/start ')) {
            return;
        }

        $user = DB::transaction(function () use ($text, $chatId): ?User {
            $code = TelegramLinkCode::query()->where('code_hash', hash('sha256', trim(substr($text, 7))))->lockForUpdate()->first();
            if ($code === null || $code->used_at !== null || $code->expires_at->isPast() || $code->user === null) {
                return null;
            }
            $code->update(['used_at' => now()]);
            $code->user->linkTelegram($chatId);

            return $code->user;
        });

        if ($user === null) {
            return;
        }

        (new RecordAuditLog)->handle('alert.telegram_linked', $user);
        // Their language as everything else resolves it (the person's, else the club's default) — not the raw column.
        SendTelegramMessage::dispatch(null, __('Conectado. Te avisaré aquí.', [], (new ResolveLocale)->handle($user)), $chatId);
    }
}
