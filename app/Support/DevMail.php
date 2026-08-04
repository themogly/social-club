<?php

namespace App\Support;

use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationInviteMail;
use App\Mail\ApplicationRejectedMail;
use App\Mail\ConvocatoriaMail;
use App\Mail\DispensationReceiptMail;
use App\Mail\ExampleClubMail;
use App\Mail\LockdownReactivationMail;
use App\Mail\MemberCardMail;
use App\Mail\MemberLoginLinkMail;
use App\Mail\MembershipReminderMail;
use App\Models\Member;
use App\Models\OrganisationLockdown;
use App\Models\User;
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
            'application-invite' => new ApplicationInviteMail('https://mi-club.example/alta/preview-token', '2026-09-30'),
            'application-approved' => new ApplicationApprovedMail('María García', 'M-00042'),
            'application-rejected' => new ApplicationRejectedMail('María García', 'Falta el documento de identidad.'),
            'convocatoria' => new ConvocatoriaMail(
                memberName: 'María García',
                title: 'Aprobación de cuentas 2026 y renovación de la junta',
                typeLabel: __('Ordinaria'),
                heldAt: '2026-09-20 18:00',
                secondCallAt: '2026-09-20 18:30',
                venue: 'Sede Centro — Calle Mayor 1',
                agenda: ['Lectura del acta anterior', 'Aprobación de cuentas 2026', 'Renovación de la junta directiva', 'Ruegos y preguntas'],
                body: 'Se ruega puntualidad. La documentación estará disponible en la sede desde una semana antes.',
                noticeDays: 15,
                quorumRequired: 42,
            ),
            'lockdown-reactivation' => new LockdownReactivationMail(
                new User(['name' => 'Ana Ruiz']),
                new OrganisationLockdown(['is_drill' => false]),
                'preview-token-not-a-real-link',
            ),
        ];
    }
}
