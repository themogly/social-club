<?php

namespace App\Http\Controllers\Member;

use App\Enums\EventRsvpStatus;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The socio's events list + a member-scoped, capacity-checked RSVP toggle. */
class EventController extends MemberController
{
    public function index(): View
    {
        $member = $this->member();
        $events = $this->visibleTo($member);

        // The member's own responses, keyed by event — never anyone else's.
        $myRsvps = EventRsvp::query()
            ->where('member_id', $member->id)
            ->whereIn('event_id', $events->pluck('id'))
            ->pluck('status', 'event_id');

        return view('socio.events', [
            'events' => $events,
            'myRsvps' => $myRsvps,
        ]);
    }

    /** Toggle (or set) the authenticated member's RSVP for an in-scope event. */
    public function rsvp(Request $request, Event $event): RedirectResponse
    {
        $member = $this->member();
        $this->assertInScope($member, $event);

        $data = $request->validate([
            'status' => ['nullable', 'in:GOING,MAYBE,DECLINED'],
        ]);

        $existing = $event->rsvps()->where('member_id', $member->id)->first();

        // No explicit status = a simple toggle of "asistiré".
        $target = $data['status'] ?? ($existing?->status === EventRsvpStatus::GOING
            ? EventRsvpStatus::DECLINED->value
            : EventRsvpStatus::GOING->value);

        if ($target === EventRsvpStatus::GOING->value && $this->wouldExceedCapacity($event, $existing?->status)) {
            return back()->withErrors(['rsvp' => __('El evento ha completado su aforo.')]);
        }

        $event->rsvps()->updateOrCreate(
            ['member_id' => $member->id],
            ['status' => $target, 'responded_at' => now()],
        );

        return back()->with('status', __('Tu respuesta se ha guardado.'));
    }

    /** Capacity is full only when adding a NEW confirmed attendee would pass the cap. */
    private function wouldExceedCapacity(Event $event, ?EventRsvpStatus $currentStatus): bool
    {
        if ($event->capacity === null) {
            return false;
        }
        if ($currentStatus === EventRsvpStatus::GOING) {
            return false; // already counted — a re-confirm never breaches
        }

        $going = $event->rsvps()->where('status', EventRsvpStatus::GOING->value)->count();

        return $going >= $event->capacity;
    }

    /** Object-ownership: the event must belong to the member's org and location scope. */
    private function assertInScope(Member $member, Event $event): void
    {
        abort_unless($event->organisation_id === $member->organisation_id, 404);

        if ($event->location_id !== null) {
            abort_unless($this->memberLocationIds($member)->contains($event->location_id), 403);
        }
    }

    /**
     * @return Collection<int, Event>
     */
    private function visibleTo(Member $member): Collection
    {
        $locationIds = $this->memberLocationIds($member)->all();

        /** @var Collection<int, Event> $events */
        $events = Event::query()->withoutGlobalScopes()
            ->where('organisation_id', $member->organisation_id)
            ->where(fn (Builder $q): Builder => $q->whereNull('location_id')->orWhereIn('location_id', $locationIds))
            ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()->subDay()))
            ->withCount(['rsvps as going_count' => fn (Builder $q): Builder => $q->where('status', EventRsvpStatus::GOING->value)])
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        return $events;
    }
}
