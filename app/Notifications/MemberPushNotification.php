<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Base for every member push. Two gates live here so no subclass can forget them:
 *
 *   1. the member's PER-CHANNEL opt-out (`Member::wantsPush()`), and
 *   2. VAPID configuration — with no keys we send NOTHING (graceful degrade), never
 *      a crash inside a queued job.
 *
 * Both are enforced in via(): an ungated channel returns `[]`, so the notification is
 * never queued or sent (this is what the opt-out test asserts under Notification::fake).
 * Queued on the webpush channel, so delivery runs through Horizon.
 */
abstract class MemberPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** The opt-out key this push belongs to (one of Member::PUSH_CHANNELS). */
    abstract protected function channelKey(): string;

    abstract public function toWebPush(object $notifiable, Notification $notification): WebPushMessage;

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        if (! self::pushConfigured()) {
            return [];
        }

        if ($notifiable instanceof Member && ! $notifiable->wantsPush($this->channelKey())) {
            return [];
        }

        return [WebPushChannel::class];
    }

    /** True only when both VAPID keys are present — otherwise no push is attempted. */
    public static function pushConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    protected function baseMessage(string $title, string $body, string $url = '/socio'): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/socio-icons/icon-192.png')
            ->badge('/socio-icons/icon-192.png')
            ->data(['url' => $url]);
    }
}
