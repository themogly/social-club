<?php

namespace App\Jobs;

use App\Actions\Mail\QueueClubMail;
use App\Actions\RecordAuditLog;
use App\Mail\TelegramAlertByEmailMail;
use App\Mail\TelegramDisconnectedMail;
use App\Models\User;
use App\Support\Telegram;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Prompt 311 — one Telegram message to one linked person (or, for the bot's replies, straight to a chat), queued with the
 * same retries as the club's mail (288). A 403 means the person blocked the bot: their chat id is cleared and they get
 * ONE email saying alerts moved to email — no retry. A final failure is audited as `alert.failed` with neither the chat
 * id nor the text.
 *
 * Prompt 363 — Sentry showed «Telegram answered 401» four times per alert while the owner's urgent alerts never arrived.
 * 401/404 mean the bot TOKEN is wrong: no retry, the alert goes to the same person by EMAIL in their language, the fault is
 * flagged for Salud del sistema and reported to Sentry once an hour. A 429 waits Telegram's `retry_after`. A timeout or a
 * 5xx keeps the retries — with a message that never carries the request URL (it holds the token).
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

        try {
            $response = Telegram::sendMessage($chatId, $this->text);
        } catch (ConnectionException) {
            // Its message is cURL's, with the URL — and the URL holds the token. Retried; never logged with it.
            throw new RuntimeException('Telegram unreachable');
        }

        if (Telegram::rejectsToken($response->status())) {
            Telegram::markTokenRejected($response->status());
            if ($user !== null) {
                (new QueueClubMail)->handle($user, new TelegramAlertByEmailMail($this->text));
            }
            (new RecordAuditLog)->handle('alert.failed', null, null, ['channel' => 'telegram', 'error' => 'token_rejected', 'fallback' => $user !== null ? 'email' : null]);

            return;
        }

        if ($response->status() === 429) {
            $this->release(max(1, (int) $response->json('parameters.retry_after', 30)));

            return;
        }

        if ($response->status() === 403) {
            if ($user !== null && $user->telegram_chat_id !== null) {
                $user->unlinkTelegram();
                (new QueueClubMail)->handle($user, new TelegramDisconnectedMail($user->name));
            }

            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Telegram answered '.$response->status());
        }

        Telegram::clearTokenRejected();
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
