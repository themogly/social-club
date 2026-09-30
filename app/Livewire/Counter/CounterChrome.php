<?php

namespace App\Livewire\Counter;

use App\Actions\Staff\ClockIn;
use App\Actions\Staff\UndoTillClockEvent;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Models\Location;
use App\Models\StaffClockEvent;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\TrainingMode;
use App\Support\WorkedHours;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The counter's chrome — the skip link and the shared top bar — as a component rather than a layout branch
 * (prompt 209).
 *
 * **The bug.** Reported: *"managed to lose the top bar when I went to sign up a new member, clicked hand
 * tablet over and clicked back."* Reproduced exactly: hand over → Back → *Personal del club* → PIN, and the
 * counter comes back, the operator is restored, the green *"Trabajando: …"* confirmation is on screen — **and
 * there is no top bar.** No sede, no lock, no way to any other screen. Since 205 the bar is the only
 * navigation, so the terminal was stranded on whatever screen it happened to be on until somebody reloaded.
 *
 * **The cause was a rendering boundary, not the server logic.** `components/layouts/counter.blade.php` asked
 * `CounterHandover::active()` to decide whether the chrome exists — which is the right RULE (173 requires the
 * chrome absent from the DOM, not hidden) evaluated in the wrong PLACE. `unlockOperator()` ends the handover
 * inside a **Livewire action**, and a Livewire response replaces the component's markup and nothing else: the
 * Blade layout is never re-rendered. So the branch had been resolved on the previous full page load, while the
 * handover was still active, and no amount of correct server state could bring the bar back.
 *
 * **This is prompt 188's failure one level out.** 188 was Alpine snapshotting server state into `x-data`; this
 * is the layout snapshotting server state into the DOM. Same shape: a fact a component can change mid-session,
 * read somewhere a component's response can never reach.
 *
 * **Why a nested component and not a redirect.** A redirect on recovery would work — and at that exact moment
 * it would cost nothing, because the handover disposed of everything the applicant touched. But
 * `unlockOperator()` is the same method for all three modes, so the reload would land on the LOCK path too,
 * where 198 and 205 deliberately preserve work; and, decisively, the layout would still be branching on
 * session state a component can change, which is the class of bug rather than the instance. Moving the branch
 * out of the layout is the only option that lets that rule be stated and guarded — the guard is
 * `tests/Feature/Counter/LayoutBranchesOnFixedFactsTest` (named as a path, not a `{@see}`: Pint would import
 * a dev-only class into production code).
 *
 * **How it comes back.** `unlockOperator()` already dispatches `counter-unlocked` — after ending the handover
 * and writing the session — so by the time this component answers that event the session is correct and it
 * simply re-renders. The idle path is the same mechanism: `counter-lock` is what makes `lockCounter()` end a
 * timed-out handover, and this listens to it too.
 */
class CounterChrome extends Component
{
    /** The screen's own name, resolved by the layout from `CounterScreens` and passed through. */
    public ?string $title = null;

    /**
     * Both events that change whether a handover is active.
     *
     * Empty bodies on purpose: answering the event IS the work — Livewire re-renders the component, and the
     * render below asks the session the question again. There is no state here to update.
     */
    #[On('counter-unlocked')]
    #[On('counter-lock')]
    public function refresh(): void {}

    /** Prompt 338 — what an undo or a clock-in here had to say (a refusal, when the 2 minutes have gone). */
    public ?string $clockMessage = null;

    /** *Deshacer* on the clock-in the till's opening wrote (an ANNUL; the person is unclocked again). */
    public function undoClockIn(): void
    {
        $operator = CounterOperator::current();
        try {
            if ($operator === null) {
                throw new DomainException(__('Ya no se puede deshacer la entrada. Si hace falta, corrígela desde el registro de jornada.'));
            }
            (new UndoTillClockEvent)->handle($operator);
            $this->clockMessage = null;
            $this->dispatch('counter-clock-state', open: false);
        } catch (DomainException $e) {
            $this->clockMessage = $e->getMessage();
        }
    }

    /** *Preguntar* → «Sí»: clock in now, TILL_OPEN — the open itself was just authorised by them. */
    public function acceptClockIn(): void
    {
        $operator = CounterOperator::current();
        $location = $this->counterLocation();
        session()->forget(TillSession::CLOCK_IN_OFFER);
        if ($operator === null || $location === null) {
            return;
        }

        try {
            (new ClockIn)->handle($operator, $location, $operator, StaffClockSource::TILL_OPEN);
            $this->clockMessage = null;
            $this->dispatch('counter-clock-state', open: true);
        } catch (AuthorizationException|DomainException|InvalidArgumentException $e) {
            $this->clockMessage = $e->getMessage();
        }
    }

    /** «No» — nothing is written. */
    public function declineClockIn(): void
    {
        session()->forget(TillSession::CLOCK_IN_OFFER);
    }

    /**
     * The till-open clock-in still undoable by the person at the PIN: 338's automatic TILL_OPEN IN, theirs, within
     * UndoTillClockEvent's window and not yet annulled — its time, for «Entrada fichada a las 18:02. [Deshacer]».
     */
    private function clockInNotice(): ?string
    {
        $operator = CounterOperator::current();
        $eventId = session(CounterOperator::CLOCK_UNDO);
        $in = $operator !== null && is_string($eventId) ? StaffClockEvent::query()->withoutGlobalScopes()->find($eventId) : null;

        if ($in === null || $in->user_id !== $operator->id || $in->type !== StaffClockType::IN || $in->source !== StaffClockSource::TILL_OPEN
            || $in->recorded_at->lt(now()->subSeconds(UndoTillClockEvent::WINDOW_SECONDS))
            || StaffClockEvent::query()->withoutGlobalScopes()->where('corrects_event_id', $in->id)->where('type', StaffClockType::ANNUL->value)->exists()) {
            return null;
        }

        return local_datetime($in->occurred_at, 'H:i', $in->location);
    }

    private function clockInOffer(): bool
    {
        $operator = CounterOperator::current();

        return $operator !== null && session(TillSession::CLOCK_IN_OFFER) === $operator->id && WorkedHours::openPeriodFor($operator) === null;
    }

    private function counterLocation(): ?Location
    {
        $sede = session('counter.location_id');

        return is_string($sede) ? Location::query()->withoutGlobalScopes()->find($sede) : null;
    }

    public function render(): View
    {
        return view('livewire.counter.counter-chrome', [
            // 173's guarantee, now evaluated somewhere a Livewire response can reach: while an applicant
            // holds the tablet there is no element to find, no link to follow and nothing for a keyboard to
            // reach — absent from the DOM, never hidden by CSS.
            'handedOver' => CounterHandover::active(),
            // Prompt 324 — *Modo formación*: the banner (on every screen, even handed over) and the practice suffix.
            'training' => TrainingMode::active(),
            'trainingAllowed' => (bool) Settings::get('counter_training_enabled', true, session('counter.location_id')),
            // Prompt 338 — the till's opening clocked this person in (with Deshacer), or asks whether to.
            'clockInNotice' => $this->clockInNotice(),
            'clockInOffer' => $this->clockInOffer(),
        ]);
    }
}
