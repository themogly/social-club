<?php

namespace App\Mail;

use App\Actions\RecordAuditLog;
use App\Jobs\Middleware\FailOnPermanentRefusal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Resend\Exceptions\ErrorException;
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

    /** Prompt 372 — who the mail is about (set by QueueClubMail), so a final `mail.failed` names the member or user. */
    public ?string $aboutType = null;

    public ?string $aboutId = null;

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * Prompt 372 — a refusal that will not change on retry fails the job at ONCE ({@see FailOnPermanentRefusal}) (one Resend call and one Sentry event per bad
     * address, not four): Resend's 4xx answers other than 408, 409 and 429 (validation and address errors). 429, 5xx and network
     * errors keep the retries and backoff above.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new FailOnPermanentRefusal];
    }

    public static function isPermanent(Throwable $e): bool
    {
        $resend = $e instanceof ErrorException ? $e : $e->getPrevious();
        if (! $resend instanceof ErrorException) {
            return false;
        }
        try {
            $code = $resend->getErrorCode();
        } catch (Throwable) {
            return false;
        }

        return $code >= 400 && $code < 500 && ! in_array($code, [408, 409, 429], true);
    }

    public function failed(Throwable $e): void
    {
        try {
            $resend = $e instanceof ErrorException ? $e : $e->getPrevious();
            $type = null;
            if ($resend instanceof ErrorException) {
                try {
                    $type = $resend->getErrorType();
                } catch (Throwable) {
                }
            }
            // The subject (prompt 372) — the member or user it was for — and never the address.
            $subject = $this->aboutType !== null && $this->aboutId !== null
                ? Relation::getMorphedModel($this->aboutType) ?? $this->aboutType : null;
            $model = is_string($subject) && class_exists($subject) ? $subject::query()->withoutGlobalScopes()->find($this->aboutId) : null;
            (new RecordAuditLog)->handle('mail.failed', $model instanceof Model ? $model : null, null, array_filter([
                'mailable' => static::class,
                'error' => class_basename($e),
                'error_type' => $type,
                'permanent' => self::isPermanent($e),
            ], fn ($v): bool => $v !== null));
        } catch (Throwable) {
            // The audit is the visibility, not the delivery: a failing audit must not mask the original failure.
        }
    }
}
