<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Mail\ApplicationInviteMail;
use App\Models\MemberApplication;
use Illuminate\Support\Facades\Mail;
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
    public function handle(MemberApplication $application): bool
    {
        if (! $application->acceptsSubmission() || blank($application->applicant_email) || $application->inviteUrl() === null) {
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
        } catch (Throwable) {
            $queued = false;
        }

        (new RecordAuditLog)->handle('application.invite.sent', $application, null, ['queued' => $queued]);

        return $queued;
    }
}
