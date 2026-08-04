<?php

namespace App\Notifications;

use App\Models\MessageThread;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushMessage;

/** The club has replied to a member's thread — pushed to the member who opted in. */
class NewMemberMessageNotification extends MemberPushNotification
{
    public function __construct(public MessageThread $thread) {}

    protected function channelKey(): string
    {
        return 'new_message';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->baseMessage(
            __('Nuevo mensaje del club'),
            Str::limit($this->thread->subject, 80),
            route('socio.messages.show', ['thread' => $this->thread->id], absolute: false),
        )->tag('message-'.$this->thread->id);
    }
}
