<?php

namespace App\Livewire\Counter;

use App\Models\MemberApplication;
use App\Support\ActiveScope;
use App\Support\CounterBasket;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Prompt 330 — a new sign-up pops up on the counter, on every screen, within seconds. Ben: "A notification at the top
 * somewhere in the till so it's really obvious… they want it just to appear and they can approve."
 *
 * A component of its OWN in the top bar, not a part of each screen: it polls every 15 s and a poll re-renders only this
 * — a couple of KB — never the dispensary under it (293's budget). It counts this sede's applications awaiting review
 * (the badge, hidden at zero) and, when one arrives that this browser session has not announced yet, shows a banner
 * under the bar: the first name and the last initial, and how long ago it was sent — never the email, phone, photo or
 * document, which stay inside the review. Its button opens the ONE review flow on Socios (329's `?solicitud=`).
 *
 * Only with an operator at the PIN and no handover (the chrome already leaves the bar out then; this checks again).
 * Without `applications.review` the badge and banner still show — someone should know — and the button says to tell a
 * responsable; a crafted review call is refused.
 */
class PendingApplicationsBell extends Component
{
    /** Ids this browser session has already announced (a banner once per application, not per poll). */
    private const SEEN = 'counter_applications_seen';

    /** Announced and not yet dismissed with *Luego* (or opened). */
    private const BANNER = 'counter_applications_banner';

    #[Locked]
    public ?string $locationId = null;

    /** A review asked for while a basket is in progress: which one (null = the Socios list), confirmed first. */
    #[Locked]
    public bool $confirming = false;

    #[Locked]
    public ?string $confirmingReviewOf = null;

    public function mount(): void
    {
        // The counter's OWN sede (89), as the top bar reads it: this mounts mid-update when a PIN unlocks the bar, where
        // the request's scope has not been applied by any screen yet.
        $sede = session('counter.location_id');
        $this->locationId = is_string($sede) ? $sede : app(ActiveScope::class)->locationId();
        $this->check();
    }

    /** The poll: announce what has arrived since this session last looked. */
    public function check(): void
    {
        if (! $this->visible()) {
            return;
        }

        $pending = $this->pending()->modelKeys();
        $seen = (array) session(self::SEEN, []);
        $new = array_values(array_diff($pending, $seen));
        if ($new === []) {
            return;
        }

        // Seen is kept to what is still pending, so it never grows past the sede's queue.
        session([
            self::SEEN => [...array_values(array_intersect($seen, $pending)), ...$new],
            self::BANNER => array_values(array_unique([...(array) session(self::BANNER, []), ...$new])),
        ]);

        $this->dispatch('applications-arrived', count: count($new),
            chime: (bool) Settings::get('applications_chime_enabled', false, $this->locationId));
    }

    /** *Luego*: the banner goes for this session; the badge stays. */
    public function later(): void
    {
        session()->forget(self::BANNER);
        $this->confirming = false;
        $this->confirmingReviewOf = null;
    }

    /** *Revisar y aprobar* (one) or *Revisar* (several: the Socios list). Asks first while a basket is in progress. */
    public function review(?string $applicationId = null): void
    {
        if (! $this->canReview()) {
            return;
        }
        if ($applicationId !== null && $this->pending()->find($applicationId) === null) {
            return;
        }

        if (CounterBasket::inProgress($this->locationId)) {
            $this->confirming = true;
            $this->confirmingReviewOf = $applicationId;

            return;
        }

        $this->goToReview($applicationId);
    }

    /** The basket is session-kept (205), so going to Socios loses nothing; the operator said so first. */
    public function confirmReview(): void
    {
        if ($this->confirming && $this->canReview()) {
            $this->goToReview($this->confirmingReviewOf);
        }
    }

    public function cancelReview(): void
    {
        $this->confirming = false;
        $this->confirmingReviewOf = null;
    }

    public function render(): View
    {
        $visible = $this->visible();
        $pending = $visible ? $this->pending() : new Collection;
        $banner = $pending->only((array) session(self::BANNER, []))->values();

        return view('livewire.counter.pending-applications-bell', [
            'visible' => $visible,
            'count' => $pending->count(),
            'label' => trans_choice(':count solicitud pendiente|:count solicitudes pendientes', $pending->count(), ['count' => $pending->count()]),
            'banner' => $banner->map(fn (MemberApplication $application): array => [
                'id' => (string) $application->id,
                'name' => self::shortName($application),
                'ago' => $application->submitted_at?->diffForHumans() ?? '',
            ])->all(),
            'canReview' => $this->canReview(),
        ]);
    }

    private function goToReview(?string $applicationId): void
    {
        // Opened: off the banner (the badge keeps counting until it is approved or rejected).
        session([self::BANNER => array_values(array_diff((array) session(self::BANNER, []), $applicationId !== null ? [$applicationId] : $this->pending()->modelKeys()))]);

        $this->redirect(route('counter.members', $applicationId !== null ? ['solicitud' => $applicationId] : []));
    }

    /** @return Collection<int, MemberApplication> */
    private function pending(): Collection
    {
        if ($this->locationId === null) {
            return new Collection;
        }

        return MemberApplication::query()->withoutGlobalScopes()->where('location_id', $this->locationId)->awaitingReview()
            ->latest('submitted_at')->limit(100)->get(['id', 'location_id', 'status', 'submitted_at', 'payload']);
    }

    private function visible(): bool
    {
        return CounterOperator::id() !== null && ! CounterHandover::active();
    }

    private function canReview(): bool
    {
        return $this->visible() && (CounterOperator::current()?->can('applications.review') ?? false);
    }

    /** "Thomas P." — the first name and the last initial, nothing more. */
    private static function shortName(MemberApplication $application): string
    {
        $p = (array) $application->payload;
        $first = trim((string) ($p['first_name'] ?? ''));
        $last = trim((string) ($p['last_name'] ?? ''));
        $name = trim($first.($last !== '' ? ' '.mb_strtoupper(mb_substr($last, 0, 1)).'.' : ''));

        return $name !== '' ? $name : __('Solicitud');
    }
}
