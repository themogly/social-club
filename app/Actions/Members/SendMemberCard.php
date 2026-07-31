<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Mail\MemberCardMail;
use App\Models\Member;
use Illuminate\Support\Facades\Mail;

/**
 * Send a member their QR card by email (prompt 85) — the SINGLE card-send path, called from both creation
 * routes (admin CreateMember + ApproveApplication) and the resend action. QUEUED, never synchronous (the
 * Horizon architecture exists for exactly this — a card send must never block the counter). Issuing a
 * credential that identifies a member at the door is a consequential act, so it is audited.
 *
 * No email ⇒ no send, no error: the member simply has no card, which is a DISCOVERABLE state
 * (Member::cardMissing()), not a silent failure or an exception.
 *
 * Token rotation: `IssueMemberToken` stores the card token HASH-ONLY (NOTES §B) — the plaintext the QR
 * encodes is unrecoverable — so a resend CANNOT re-use the old token and necessarily rotates (the previous
 * card stops working). This diverges from prompt 45's invite reuse, but deliberately: invites store the
 * encrypted raw token and CAN be re-sent unchanged; a QR card cannot, and the security rule wins. Recorded
 * in DECISIONS.
 */
class SendMemberCard
{
    /** Returns true if the card was queued, false if the member has no email (nothing to send). */
    public function handle(Member $member): bool
    {
        if (blank($member->email)) {
            return false;
        }

        $token = (new IssueMemberToken)->handle($member);

        Mail::to($member->email)->queue(new MemberCardMail($member, $token));

        // Audit the ACT, never the address (the audit log has longer retention — prompt 76).
        (new RecordAuditLog)->handle('member.card.sent', $member, null, ['channel' => 'email']);

        return true;
    }
}
