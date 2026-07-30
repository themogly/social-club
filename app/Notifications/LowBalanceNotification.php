<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** The member's wallet has fallen below the low-balance threshold. */
class LowBalanceNotification extends MemberPushNotification
{
    public function __construct(public int $balanceCents) {}

    protected function channelKey(): string
    {
        return 'low_balance';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->baseMessage(
            __('Saldo bajo en tu monedero'),
            __('Tu saldo es de :amount. Puedes recargarlo en tu próxima visita.', [
                'amount' => Money::fromCents($this->balanceCents)->formatted(),
            ]),
            route('socio.home', absolute: false),
        )->tag('low-balance');
    }
}
