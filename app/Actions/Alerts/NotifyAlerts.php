<?php

namespace App\Actions\Alerts;

use App\Jobs\SendTelegramMessage;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Support\Alerts\AlertMessage;
use App\Support\Alerts\AlertRecipients;
use App\Support\Telegram;
use Illuminate\Support\Collection;

/**
 * Prompt 311 — announce this run's NEW alerts by Telegram: ONE grouped message per person (an owner never gets twelve
 * pings at once), in their own language, holding only the alerts they take for the sedes they chose. With no bot token,
 * Telegram is off and nothing is sent; the morning email still goes ({@see SendMorningSummaries}).
 */
class NotifyAlerts
{
    /** @param  Collection<int, OwnerAlertState>  $new */
    public function handle(Organisation $organisation, Collection $new): void
    {
        if ($new->isEmpty()) {
            return;
        }

        if (Telegram::configured()) {
            foreach (AlertRecipients::for($organisation) as $recipient) {
                $user = $recipient['user'];
                if ($user->telegram_chat_id === null || ! in_array('telegram', $user->alertChannels(), true)) {
                    continue;
                }
                $mine = AlertRecipients::relevant($recipient, $new);
                if ($mine->isNotEmpty()) {
                    SendTelegramMessage::dispatch($user->id, AlertRecipients::inTheirLanguage($user, fn (): string => AlertMessage::text($mine)));
                }
            }
        }

        OwnerAlertState::query()->withoutGlobalScopes()->whereIn('id', $new->pluck('id')->all())->update(['notified_at' => now()]);
    }
}
