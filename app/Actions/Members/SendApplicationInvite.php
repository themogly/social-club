<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Mail\ApplicationInviteMail;
use App\Models\MemberApplication;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * THE one place the invitation email is queued (prompt 287) — for the panel's Invitar and Reenviar and the counter's invite
 * card and its Reenviar. 149 split creating an invitation ({@see IssueApplicationInvite}) from mailing it, "best-effort by
 * the caller"; the counter's caller, added later, never mailed and told staff it had. One sender closes that.
 *
 * Best-effort, as 149 requires: a failure to queue returns false and never removes the invitation or hides its link. It
 * refuses (false, nothing queued) anything but an outstanding invitation with an email. The audit entry records the
 * application and whether the mail was queued — never the address, never the token.
 */
class SendApplicationInvite
{
    /** Post-296 audit (A·8) — one email per invitation every 10 minutes, five a day; twenty an hour per sender. */
    public const COOLDOWN_SECONDS = 600;

    public const PER_INVITATION_PER_DAY = 5;

    public const PER_SENDER_PER_HOUR = 20;

    /** Why the last call sent nothing, in words for staff — null when it sent, or when there was nothing to send. */
    public ?string $refusal = null;

    public function handle(MemberApplication $application): bool
    {
        $this->refusal = null;
        if (! $application->acceptsSubmission() || blank($application->applicant_email) || $application->inviteUrl() === null) {
            return false;
        }

        // Post-296 audit (A·8) — club-branded mail to any address, in a loop, burns the sender's reputation: limited
        // per invitation and per person sending. The limits are counted only for mail actually queued.
        $recent = 'invite-mail:recent:'.$application->getKey();
        $daily = 'invite-mail:day:'.$application->getKey();
        $sender = 'invite-mail:sender:'.(Auth::id() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($recent, 1) || RateLimiter::tooManyAttempts($daily, self::PER_INVITATION_PER_DAY)) {
            $this->refusal = __('Esta invitación se acaba de enviar. Espera unos minutos antes de volver a enviarla.');
        } elseif (RateLimiter::tooManyAttempts($sender, self::PER_SENDER_PER_HOUR)) {
            $this->refusal = __('Has enviado muchas invitaciones en poco tiempo. Espera un rato antes de enviar más.');
        }
        if ($this->refusal !== null) {
            (new RecordAuditLog)->handle('application.invite.throttled', $application);

            return false;
        }

        try {
            Mail::to((string) $application->applicant_email)
                ->locale((new ResolveLocale)->handle())
                ->queue(new ApplicationInviteMail(
                    (string) $application->inviteUrl(),
                    $application->invite_expires_at?->format('d/m/Y') ?? '',
                ));
            $queued = true;
            RateLimiter::hit($recent, self::COOLDOWN_SECONDS);
            RateLimiter::hit($daily, 86400);
            RateLimiter::hit($sender, 3600);
        } catch (Throwable) {
            $queued = false;
        }

        (new RecordAuditLog)->handle('application.invite.sent', $application, null, ['queued' => $queued]);

        return $queued;
    }
}
