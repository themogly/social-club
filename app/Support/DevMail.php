<?php

namespace App\Support;

use App\Enums\AlertType;
use App\Mail\AlertSummaryMail;
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
use App\Mail\TelegramAlertByEmailMail;
use App\Mail\TelegramDisconnectedMail;
use App\Models\Location;
use App\Models\Member;
use App\Models\OrganisationLockdown;
use App\Models\OwnerAlertState;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            'dispensation-receipt' => new DispensationReceiptMail('María García', '2026-08-02 20:15', '3.50 g', '26.25 €'),
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
            // Prompt 311 — the owner alerts' two emails (unsaved states: a preview, no rows written).
            'alert-summary' => new AlertSummaryMail(new EloquentCollection([
                (new OwnerAlertState(['type' => AlertType::RESTOCK_FROM_STORE, 'subject' => 'genetic:preview', 'detail' => [
                    'name' => 'Amnesia Haze', 'unit' => false, 'on_hand' => 3800, 'days' => 2.4, 'out' => false,
                    'store' => ['quantity' => 85000, 'names' => ['Almacén'], 'location_id' => 'preview'],
                ]]))->setRelation('location', new Location(['name' => 'Sede Centro'])),
                (new OwnerAlertState(['type' => AlertType::PRODUCTS_LOW, 'subject' => 'article:preview', 'detail' => ['name' => 'Papel', 'stock' => 3, 'threshold' => 10]]))
                    ->setRelation('location', new Location(['name' => 'Sede Centro'])),
                // Prompts 367 / 375 — the losses alerts: the sede's day (with its euro figure, Ben) and the people this week (no names).
                (new OwnerAlertState(['type' => AlertType::LOSSES_ABOVE_THRESHOLD, 'subject' => 'losses:preview', 'detail' => ['cents' => 4620, 'pct' => '8.2', 'url' => url('/informes/perdidas?period=yesterday')]]))
                    ->setRelation('location', new Location(['name' => 'Sede Norte'])),
                (new OwnerAlertState(['type' => AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD, 'subject' => 'losses-people:preview', 'detail' => ['count' => 1, 'pct' => '16', 'url' => url('/informes/perdidas?period=last7&sort=pct')]]))
                    ->setRelation('location', new Location(['name' => 'Sede Norte'])),
            ])),
            'telegram-disconnected' => new TelegramDisconnectedMail('Ana Ruiz'),
            'telegram-alert-by-email' => new TelegramAlertByEmailMail("⚠️ Sede Centro\n· Stock bajo: Amnesia Haze (12.00 g)"), // prompt 363
            'lockdown-reactivation' => new LockdownReactivationMail(
                new User(['name' => 'Ana Ruiz']),
                new OrganisationLockdown(['is_drill' => false]),
                'preview-token-not-a-real-link',
            ),
        ];
    }
}
