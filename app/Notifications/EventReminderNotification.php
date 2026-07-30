<?php

namespace App\Notifications;

use App\Enums\EventRsvpStatus;
use App\Models\Event;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** A reminder for an upcoming event, pushed to socios who RSVP'd GOING and opted in. */
class EventReminderNotification extends MemberPushNotification
{
    public function __construct(public Event $event) {}

    protected function channelKey(): string
    {
        return 'event_reminder';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $when = $this->event->starts_at?->format('d/m/Y H:i') ?? '';

        return $this->baseMessage(
            __('Recordatorio: :title', ['title' => $this->event->title]),
            $when === '' ? (string) $this->event->title : __('Te esperamos el :when.', ['when' => $when]),
            route('socio.events', absolute: false),
        )->tag('event-'.$this->event->id);
    }

    /** Remind every socio who RSVP'd GOING (opt-out applied in via()). */
    public static function dispatchFor(Event $event): void
    {
        self::goingMembers($event)
            ->filter(fn (Member $m): bool => $m->wantsPush('event_reminder'))
            ->each(fn (Member $m) => $m->notify(new self($event)));
    }

    /**
     * @return Collection<int, Member>
     */
    protected static function goingMembers(Event $event): Collection
    {
        /** @var Collection<int, Member> $members */
        $members = Member::query()->withoutGlobalScopes()
            ->whereIn('id', $event->rsvps()
                ->where('status', EventRsvpStatus::GOING->value)
                ->pluck('member_id'))
            ->get();

        return $members;
    }
}
