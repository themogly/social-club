<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A temporary member's short-stay window is closing (prompt 111). Sent once,
 * `temporary_reminder_lead_days` before `temporary_expires_at`, so the member knows their access ends
 * — and can ask to become a full socio — before the auto-anonymisation sweep removes them.
 */
class TemporaryAccessEndingNotification extends MemberPushNotification
{
    public function __construct(public string $endsOn) {}

    protected function channelKey(): string
    {
        return 'temporary_ending';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->baseMessage(
            __('Tu acceso temporal termina pronto'),
            __('Tu acceso como socio/a temporal termina el :date. Habla con el club si quieres continuar.', ['date' => $this->endsOn]),
            route('socio.home', absolute: false),
        )->tag('temporary-ending');
    }
}
