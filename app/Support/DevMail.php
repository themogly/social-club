<?php

namespace App\Support;

use App\Mail\ExampleClubMail;
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
        ];
    }
}
