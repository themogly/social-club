<?php

namespace App\Support;

use App\Mail\ExampleClubMail;
use App\Mail\MemberCardMail;
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
        ];
    }
}
