<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** The member's membership is expiring soon (mirrors the renewal-reminder email). */
class MembershipExpiringNotification extends MemberPushNotification
{
    public function __construct(public string $expiresOn) {}

    protected function channelKey(): string
    {
        return 'membership_expiring';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->baseMessage(
            __('Tu membresía vence pronto'),
            __('Tu membresía vence el :date. Renuévala en tu próxima visita.', ['date' => $this->expiresOn]),
            route('socio.home', absolute: false),
        )->tag('membership-expiring');
    }
}
