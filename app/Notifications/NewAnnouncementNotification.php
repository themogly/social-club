<?php

namespace App\Notifications;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Announcement;
use App\Models\Member;
use App\Models\Membership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushMessage;

/** A newly published announcement, pushed to in-scope socios who opted in. */
class NewAnnouncementNotification extends MemberPushNotification
{
    public function __construct(public Announcement $announcement) {}

    protected function channelKey(): string
    {
        return 'new_announcement';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->baseMessage(
            $this->announcement->title,
            Str::limit((string) $this->announcement->body, 120),
            route('socio.announcements', absolute: false),
        )->tag('announcement-'.$this->announcement->id);
    }

    /**
     * Notify every in-scope socio of a just-published announcement. A null location
     * reaches the whole org; a scoped one reaches active members at that location.
     * The per-channel opt-out is applied in via() (belt), and pre-filtered here (braces).
     */
    public static function dispatchFor(Announcement $announcement): void
    {
        if (! $announcement->published_at || $announcement->published_at->isFuture()) {
            return;
        }
        if ($announcement->expires_at && $announcement->expires_at->isPast()) {
            return;
        }

        self::inScopeMembers($announcement)
            ->filter(fn (Member $m): bool => $m->wantsPush('new_announcement'))
            ->each(fn (Member $m) => $m->notify(new self($announcement)));
    }

    /**
     * @return Collection<int, Member>
     */
    protected static function inScopeMembers(Announcement $announcement): Collection
    {
        $query = Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $announcement->organisation_id)
            ->where('status', MemberStatus::ACTIVE->value);

        if ($announcement->location_id !== null) {
            // Members with an active membership at the targeted sede (subquery on ids
            // avoids a scoped whereHas — the member guard sets no ActiveScope location).
            $memberIds = Membership::query()->withoutGlobalScopes()
                ->where('location_id', $announcement->location_id)
                ->where('status', MembershipStatus::ACTIVE->value)
                ->pluck('member_id');

            $query->whereIn('id', $memberIds);
        }

        /** @var Collection<int, Member> $members */
        $members = $query->get();

        return $members;
    }
}
