<?php

namespace App\Actions\Mail;

use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Mail\ClubMail;
use App\Models\Member;
use App\Models\User;
use App\Support\Email;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

/**
 * Prompt 372 — the ONE place the app queues an email (a guard test refuses `Mail::to(` anywhere else in app/, the /dev/mail
 * preview aside). A production Horizon worker failed four times on «Invalid `to` field» for a member whose address had a
 * space in it, then logged `mail.failed` naming nobody.
 *
 *  - The address is checked with {@see Email::isSendable()}: an unsendable one is NOT queued — `mail.skipped_invalid_address`
 *    is audited with the subject (the member or user, or `$about`) and the mailable's class, never the address (prompt 76) —
 *    and the caller is told `false`.
 *  - It goes to the BARE address, never a display name (Members already did; users now match).
 *  - The recipient's language is resolved here, in-request, and pinned onto the queued message (prompt 96).
 *  - The subject rides on the mailable (`aboutType` / `aboutId`) so a final `mail.failed` names who it was for.
 */
class QueueClubMail
{
    public function handle(Member|User|string $to, ClubMail $mail, ?Model $about = null, ?string $locale = null): bool
    {
        if ($this->refuses($to, $mail::class, $about)) {
            return false;
        }
        $address = $to instanceof Model ? $to->getAttribute('email') : $to;
        $subject = $about ?? ($to instanceof Model ? $to : null);

        $mail->aboutType = $subject?->getMorphClass();
        $mail->aboutId = $subject === null ? null : (string) $subject->getKey();
        $locale ??= (new ResolveLocale)->handle($to instanceof Model ? $to : null);

        Mail::to((string) Email::normalise($address))->locale($locale)->queue($mail);

        return true;
    }

    /**
     * Is this address unsendable? Then the skip is audited (subject and mailable, never the address) and the answer is true.
     * For a sender that must check BEFORE building the mail — SendMemberCard rotates the card token, and a card must not die
     * for a mail that will never leave.
     *
     * @param  class-string<ClubMail>  $mailable
     */
    public function refuses(Member|User|string $to, string $mailable, ?Model $about = null): bool
    {
        $address = $to instanceof Model ? $to->getAttribute('email') : $to;
        if (Email::isSendable(is_string($address) ? $address : null)) {
            return false;
        }
        (new RecordAuditLog)->handle('mail.skipped_invalid_address', $about ?? ($to instanceof Model ? $to : null), null, ['mailable' => $mailable]);

        return true;
    }
}
