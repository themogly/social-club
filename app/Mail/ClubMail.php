<?php

namespace App\Mail;

use App\Actions\RecordAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Every email the club sends (prompt 288) — queued, retried, never before its data exists, and never lost in silence.
 *
 * - **Retries:** 4 tries with 30 s / 2 min / 10 min backoff, so a network hiccup or a Resend rate-limit recovers within
 *   ~12 minutes. Horizon's supervisor keeps `tries => 1` for other jobs, which may not be safe to retry; a mail can.
 * - **After commit** ({@see ShouldQueueAfterCommit}): a mail never leaves before the rows it describes are committed —
 *   `MemberCardMail`/`MemberLoginLinkMail` serialise a Member, and a job that outran its transaction would fail for good.
 * - **A lost email is visible:** once the retries are spent, `failed()` writes `mail.failed` with the mailable's class
 *   (never the address) into the club's own audit log, not only Horizon's failed jobs.
 *
 * `ExampleClubMail` (the /dev/mail preview) is the one mailable that does not extend this.
 */
abstract class ClubMail extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 4;

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function failed(Throwable $e): void
    {
        try {
            (new RecordAuditLog)->handle('mail.failed', null, null, ['mailable' => static::class, 'error' => class_basename($e)]);
        } catch (Throwable) {
            // The audit is the visibility, not the delivery: a failing audit must not mask the original failure.
        }
    }
}
