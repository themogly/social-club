<?php

namespace App\Support;

use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationRejectedMail;
use App\Mail\DispensationReceiptMail;
use App\Mail\ExampleClubMail;
use App\Mail\MemberCardMail;
use App\Mail\MemberLoginLinkMail;
use App\Mail\MembershipReminderMail;
use App\Models\Member;
use Illuminate\Mail\Mailable;

/**
 * The single registry of every mailable, with example data.
 *
 * Both the local /dev/mail preview and the permanent MailRenderTest iterate this
 * list — so every new mailable MUST be added here. A mailable absent from this
 * list renders nowhere and is never regression-tested.
 *
 * @return array<string, Mailable>
 */
class DevMail
{
    /**
     * @return array<string, Mailable>
     */
    public static function previews(): array
    {
        return [
            'example-club-mail' => new ExampleClubMail(memberName: 'María García'),
            'member-card' => new MemberCardMail(
                new Member(['first_name' => 'María', 'last_name' => 'García', 'member_no' => 'M-00042']),
                'preview-token-not-a-real-card',
            ),
            'membership-reminder' => new MembershipReminderMail('María García', '2026-09-30'),
            'dispensation-receipt' => new DispensationReceiptMail('María García', '2026-08-02 20:15', '3,50 g', '26,25 €'),
            'member-login-link' => new MemberLoginLinkMail(
                new Member(['first_name' => 'María', 'last_name' => 'García', 'member_no' => 'M-00042']),
                'preview-token-not-a-real-link',
            ),
            'application-approved' => new ApplicationApprovedMail('María García', 'M-00042'),
            'application-rejected' => new ApplicationRejectedMail('María García', 'Falta el documento de identidad.'),
        ];
    }
}
