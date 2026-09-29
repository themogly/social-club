<?php

namespace App\Jobs;

use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Mail\TelegramDisconnectedMail;
use App\Models\User;
use App\Support\Telegram;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Prompt 311 — one Telegram message to one linked person (or, for the bot's replies, straight to a chat), queued with the
 * same retries as the club's mail (288). A 403 means the person blocked the bot: their chat id is cleared and they get
 * ONE email saying alerts moved to email — no retry. A final failure is audited as `alert.failed` with neither the chat
 * id nor the text.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public ?string $userId, public string $text, public ?string $chatId = null) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        if (! Telegram::configured()) {
            return;
        }

        $user = $this->userId !== null ? User::query()->find($this->userId) : null;
        $chatId = $this->chatId ?? $user?->telegram_chat_id;
        if ($chatId === null) {
            return;
        }

        $response = Telegram::sendMessage($chatId, $this->text);

        if ($response->status() === 403) {
            if ($user !== null && $user->telegram_chat_id !== null) {
                $user->unlinkTelegram();
                Mail::to($user)->locale((new ResolveLocale)->handle($user))->queue(new TelegramDisconnectedMail($user->name));
            }

            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Telegram answered '.$response->status());
        }
    }

    public function failed(Throwable $e): void
    {
        try {
            (new RecordAuditLog)->handle('alert.failed', null, null, ['channel' => 'telegram', 'error' => class_basename($e)]);
        } catch (Throwable) {
            // The audit is the visibility, not the delivery.
        }
    }
}
