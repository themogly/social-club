<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Counter\SignInOperator;
use App\Actions\RecordAuditLog;
use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Actions\UnlockOperator;
use App\Enums\StaffClockSource;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use App\Support\CounterBlocker;
use App\Support\CounterHandover;
use App\Support\CounterLastSale;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use App\Support\Period;
use App\Support\WorkedHours;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\On;

/**
 * PIN operator identification, shared by every counter screen (prompt 02/26). A till
 * runs under ONE device login but MANY staff; each identifies with a personal PIN so
 * the operator recorded on a transaction is the PERSON who did it, never the device
 * session user. The backend (hashed pin, {@see UnlockOperator} with rate limiting,
 * {@see CounterOperator} session store) already existed and is reused verbatim — this
 * trait is the missing wiring + UI surface.
 *
 * The composing component must expose `resolveLocation(): ?\App\Models\Location`,
 * `flash(string, string): void`, and the `$noLocation` state from {@see ResolvesCounterLocation}
 * — every counter component already does all three.
 */
trait IdentifiesOperator
{
    use RegistersCounterTerminal;

    /** Bound to the PIN pad. Never persisted, never logged, cleared after every attempt. */
    public string $operatorPin = '';

    public bool $operatorPanelOpen = false;

    /** Last unlock feedback for the panel (wrong PIN / locked out); never the PIN itself. */
    public ?string $operatorFeedback = null;

    /** Prompt 286 — is the PIN pad locked out right now? Refreshed on every response ({@see dehydrateIdentifiesOperator}). */
    public bool $pinLocked = false;

    /**
     * {@see surfaceMode()} as a PROPERTY, so the client can read it LIVE (prompt 188).
     *
     * The surface used to snapshot the mode into Alpine's `x-data` at init. Livewire preserves the DOM
     * across a re-render, so `x-data` is never re-evaluated and the component kept whatever value it
     * started with. After a successful `unlockOperator()` the server's mode was null while Alpine still
     * held 'unidentified', so the surface stayed up over a counter that was, server-side, perfectly ready
     * — and only a manual reload cleared it. The state was right; only the client's copy was stale.
     *
     * A property is in the Livewire snapshot, and `$wire` is reactive, so an Alpine effect reading it
     * re-runs when the server changes it. That fixes every transition at once — identify, switch operator,
     * lock, unlock, enter and leave handover — rather than the one that happened to be reported.
     */
    public ?string $surfaceModeState = null;

    /**
     * The registro de jornada question the surface is asking (prompt 281, Ben's 280), server-side so it survives a
     * re-render: 'in' — "Fichar entrada / Solo identificarme" after a PIN with no open period; 'declare' — a period left
     * open from a previous business day, whose end is declared first; 'out' — "Fichar salida", awaiting the PIN again.
     */
    public ?string $clockPrompt = null;

    /** The declared end of a forgotten period, as the sede's local "Y-m-d\TH:i" (datetime-local). */
    public string $declaredEnd = '';

    public string $declaredReason = '';

    public ?string $clockFeedback = null;

    /** "Mis horas" — the signed-in person's own hours sheet. */
    public bool $myHoursOpen = false;

    /**
     * Refresh the mirror on EVERY render, before the view and before the snapshot is built. A hook, not a
     * line in each transition: the whole defect was one path forgetting to tell the client, and a rule
     * that has to be remembered in six places will be forgotten in a seventh.
     */
    public function renderingIdentifiesOperator(mixed $view, mixed $data): void
    {
        $this->surfaceModeState = $this->surfaceMode();
    }

    /**
     * Which mode the counter's one full-screen surface is in, resolved SERVER-side (prompt 173).
     * `locked` is client-state (the idle timer) and is layered on top of this in the surface itself.
     *
     *   handover      an applicant is holding the tablet — outranks everything
     *   unidentified  the chain has REACHED the operator step and no operator is identified
     *   null          an operator is working, or an earlier precondition is still unmet
     *
     * Prompt 187 — it asks the chain whether it is its turn. It used to raise on "no operator" ALONE, which
     * deadlocked a fresh terminal: with neither sede nor operator set, {@see CounterBlocker} correctly says
     * SEDE and the screen renders the in-page sede blocker — and then this surface painted over it at z-50,
     * taking the top bar, and with it the only control that can choose a sede. The operator was asked for a
     * PIN that {@see UnlockOperator} must then refuse for want of a location, with no way back.
     * No route out of the surface without an operator, no route to an operator without a sede.
     *
     * SEDE is the only link ahead of OPERATOR in the chain, so those two answer the question completely —
     * TILL and MEMBER come after and cannot preempt it. `$noLocation` is set by {@see ResolvesCounterLocation},
     * which every counter screen composes alongside this trait.
     */
    public function surfaceMode(): ?string
    {
        if (CounterHandover::active()) {
            return 'handover';
        }

        if ($this->clockPrompt !== null) {
            return 'clock';
        }

        $blocker = CounterBlocker::first([
            CounterBlocker::SEDE => ! $this->noLocation,
            CounterBlocker::OPERATOR => $this->hasOperator(),
        ]);

        return $blocker === CounterBlocker::OPERATOR ? 'unidentified' : null;
    }

    /** Is the tablet currently in an applicant's hands? Drives DOM-absence of the counter's chrome. */
    public function handoverActive(): bool
    {
        return CounterHandover::active();
    }

    /**
     * Hand the tablet over. Entered ONLY from the counter, by an identified operator, at a resolved sede —
     * never by URL, because nothing routes here. Signing the operator out is deliberate and mirrors
     * lockCounter(): while an applicant holds the device, requireOperator() refuses every write
     * server-side, so the surface is not the only thing standing between a tap and a commit.
     */
    public function beginHandover(?string $returnUrl = null): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        $operator = CounterOperator::current();
        $location = $this->resolveLocation();

        CounterHandover::begin((string) $operator?->id, $location?->id, $returnUrl);
        (new RecordAuditLog)->handle('counter.handover.started', $location);

        CounterOperator::clear();
        $this->forgetLastOutcome();
    }

    public function currentOperatorName(): ?string
    {
        return CounterOperator::current()?->name;
    }

    /**
     * THE counter's permission question, asked of the PIN operator — never the tablet's login (prompt 255).
     *
     * *"The device is the club's, the sede is the manager's, the PIN is the person's"* (239/246). Every screen
     * used to answer this with `Auth::user()->can()`, so on an owner-logged tablet a STAFF PIN passed owner-only
     * checks — a staff operator closed the till (`till.close` is manager+). One implementation now, shared by
     * every screen and every view flag. With nobody identified it authorises NOTHING: there is no fallback to
     * the device account, because tablets are logged in under different accounts (Ben: "varies per tablet"),
     * some of them the owner, and a fallback would hand that account's rights to whoever is standing there.
     */
    public function userCan(string $permission): bool
    {
        return $this->counterActor()?->can($permission) ?? false;
    }

    /**
     * The person at the counter — the actor for every permission check and every `*_by` written from a counter
     * screen (prompt 255). The same source that already attributes the money (`operator_id`), so there is one
     * answer to "who did this", not two.
     */
    protected function counterActor(): ?User
    {
        return CounterOperator::current();
    }

    /**
     * The tablet's login. It decides only what the TERMINAL can open — which sedes (`LocationSwitcher`) and
     * which screens (the mount gates, `CounterScreens`) — because those must resolve before anyone has typed a
     * PIN, or the PIN pad itself could never render. It never authorises an action and is never an actor. The
     * one other use is binding a signed vault URL to the session that will fetch it.
     */
    protected function deviceUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** May the tablet's login open this screen at all? Mount gates only — see {@see deviceUser()}. */
    protected function deviceCan(string $permission): bool
    {
        $user = $this->deviceUser();

        // Prompt 289 — a registered counter with nobody signed in mounts the screen behind its lock surface; nothing on it
        // is readable or doable without a PIN operator (userCan → counterActor).
        return $user !== null ? $user->can($permission) : CounterTerminals::current() !== null;
    }

    public function hasOperator(): bool
    {
        return CounterOperator::id() !== null;
    }

    public function operatorLockedOut(): bool
    {
        return (new UnlockOperator)->isLockedOut($this->operatorThrottleKey());
    }

    /** Seconds until the pad accepts a PIN again (0 when not locked) — the pad shows the countdown. */
    public function operatorLockoutSeconds(): int
    {
        return (new UnlockOperator)->lockoutSecondsRemaining($this->operatorThrottleKey());
    }

    /**
     * Lock the counter (prompt 120): the idle timer or the manual "lock now" button dispatches `counter-lock`,
     * and locking simply signs the operator OUT. That reuses the existing gate — every commit already calls
     * requireOperator() and now finds no operator, so writes are refused SERVER-SIDE, not just hidden behind the
     * overlay. Basket/session state is untouched, so unlocking (any valid PIN) resumes exactly where it left off.
     */
    #[On('counter-lock')]
    public function lockCounter(): void
    {
        // If the applicant wandered off holding the tablet, the timer must land on the LOCK screen, not
        // return an abandoned device to a live till (prompt 173). Ending the handover here also disposes
        // of whatever they had typed, which is the same guarantee as completing or aborting.
        if (CounterHandover::active()) {
            (new RecordAuditLog)->handle('counter.handover.timed_out', $this->resolveLocation());
            CounterHandover::end();
        }

        CounterOperator::clear();
        CounterLastSale::forget(); // prompt 347 — a lock ends the hub's last-sale line
        $this->dispatch('counter-clock-state', open: false);

        // Prompt 289 — on a REGISTERED counter "locked" means nobody is signed in, not "whoever typed last": the person is
        // signed out, the session (basket, sede) is kept and its id regenerated. An ordinary browser keeps its login.
        // Post-296 audit — also when the tablet was revoked since the PIN sign-in (it is no longer "current").
        if ((CounterTerminals::current() !== null || is_string(session('counter.terminal_id'))) && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            session()->forget(['counter.terminal_id', 'auth.via_pin']);
            session()->regenerate();
        }

        // Prompt 198 keeps the BASKET across a lock, deliberately — work survives a step away from the screen.
        // A confirmation is not work; it is a receipt for a transaction that is over, and whoever unlocks may
        // not be who it belongs to (prompt 202).
        $this->forgetLastOutcome();
    }

    public function openOperatorPanel(): void
    {
        $this->operatorPanelOpen = true;
        $this->operatorPin = '';
        $this->operatorFeedback = null;
    }

    /**
     * Sign the current operator out and reopen the pad for the next person.
     *
     * Listening for a browser-dispatched event as well (prompt 205): the operator chip now lives in the
     * shared top bar, which renders in the LAYOUT and is therefore outside every Livewire component's DOM —
     * `$wire` is not reachable from there. `Livewire.dispatch` is the same mechanism the idle lock already
     * uses for `counter-lock`, and it keeps 173's rule that there is exactly one PIN pad: this reopens that
     * surface rather than drawing a second one.
     */
    #[On('counter-switch-operator')]
    public function switchOperator(): void
    {
        CounterOperator::clear();
        $this->forgetLastOutcome();
        $this->openOperatorPanel();
    }

    /**
     * Drop the last commit's figures, if this screen shows any (prompt 202).
     *
     * Locking and switching operator both mean the person in front of the screen may have changed, and a
     * stale *"Cambio €5,40"* is worse than no confirmation at all — the next operator will act on it. The
     * BASKET is deliberately untouched: prompt 120's guarantee is that work survives a lock. What does not
     * survive is a receipt for somebody else's transaction.
     *
     * Guarded rather than declared on this trait because only the two POS screens settle money.
     */
    private function forgetLastOutcome(): void
    {
        if (method_exists($this, 'clearSettledOutcome')) {
            $this->clearSettledOutcome();
        }

        // Every counter screen declares `$flashMessage`; the confirmation IS that flash, so it goes too.
        $this->flashMessage = null;
    }

    /**
     * Verify the entered PIN against the location's active staff and, on success, set the operator.
     *
     * Prompt 286 — returns the outcome to the pad's promise (`$wire.unlockOperator().then(…)`), so the pad can say
     * "Hola, Marta" or shake, and hold its "checking" state until the answer is in: one request in flight, never a
     * retype queued behind it (each retype that lands wrong costs an attempt against the lockout).
     *
     * @return array{ok: bool, name?: string}
     */
    public function unlockOperator(): array
    {
        $location = $this->resolveLocation();

        if ($location === null) {
            // Prompt 187: once the surface asks the chain, an unidentified operator should never see this —
            // the sede step blocks first. It IS still reachable from the LOCKED mode, which raises on client
            // state regardless of the chain, so it names the fix and where to find it rather than only the
            // precondition. "Sin sede activa." was accurate and useless.
            $this->operatorFeedback = __('Sin sede activa. Elige tu sede en la barra superior antes de identificarte.');

            return ['ok' => false];
        }

        $pin = trim($this->operatorPin);
        $this->operatorPin = '';                     // never keep the PIN in component state

        if ($pin === '') {
            $this->operatorFeedback = __('Introduce tu PIN.');

            return ['ok' => false];
        }

        $operator = (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());

        if ($operator === null) {
            $this->operatorFeedback = $this->pinFailureMessage();

            return ['ok' => false];
        }

        // The PIN is how EVERY mode ends — locked, unidentified and handed over alike. Ending a handover
        // here is what makes "there is no way out except the PIN" true rather than aspirational. The submitted
        // application id (if any) is read BEFORE end() disposes of the handover, so the operator can be carried
        // to its review below.
        $submittedAlta = null;
        $wasHandover = CounterHandover::active();
        if ($wasHandover) {
            $submittedAlta = CounterHandover::submittedApplicationId();
            (new RecordAuditLog)->handle('counter.handover.ended', $this->resolveLocation());
            CounterHandover::end();
        }

        // Prompt 267 — the PIN IS a sign-in: the session becomes this person everywhere (counter, panel, audit).
        (new SignInOperator)->handle($operator, $location);
        $this->operatorPanelOpen = false;
        $this->operatorFeedback = null;
        // Tell the idle-lock overlay (prompt 120) to lift — the same PIN pad both identifies a new operator and
        // unlocks an idle-locked screen, so a successful unlock always clears the overlay.
        $this->dispatch('counter-unlocked');
        $this->flash(__('Trabajando: :name', ['name' => $operator->name]), 'success');

        // Prompt 281 — the registro de jornada question, ONLY when this person has no open period (the idle-lock
        // unlock stays one step: a question on every unlock would be tapped through blindly within a day) and never
        // after a handover. A period left open from an earlier business day is declared first — never invented.
        if (! $wasHandover) {
            $this->clockPrompt = $this->clockPromptFor($operator, $location);
        }
        $this->dispatch('counter-clock-state', open: WorkedHours::openPeriodFor($operator) !== null);

        // Prompt 249 — when the handover that just ended had a SUBMITTED form, land the operator on its review
        // (the counter's 243 rule: the screen shows the outcome, no "revísala" flash). Prompt 188 rejected a
        // redirect after identifying to preserve basket and form state — but a handover has already disposed of
        // both (173's "nothing survives"), so this ONE case is exempt. Any other unlock — locked, unidentified,
        // or a handover abandoned before submit — stays exactly where it is.
        if ($submittedAlta !== null) {
            $this->redirect(route('counter.members', ['alta' => $submittedAlta]));
        }

        return ['ok' => true, 'name' => Str::before(trim((string) $operator->name), ' ')];
    }

    /**
     * Which clock question, if any, a fresh sign-in should see. Every change to the open period is also announced to the
     * browser as `counter-clock-state` — the top bar's "Fichar salida" is drawn once per page and follows it.
     */
    private function clockPromptFor(User $operator, Location $location): ?string
    {
        $open = WorkedHours::openPeriodFor($operator);

        if ($open === null) {
            return 'in';
        }

        $today = BusinessDay::today($open->location);
        $this->declaredEnd = '';
        $this->declaredReason = '';

        return $open->business_date->toDateString() < $today ? 'declare' : null;
    }

    /** "Fichar entrada" — an IN at now, confirmed by the PIN just entered. */
    public function clockInNow(): void
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();

        // Guarded by the FACTS, not by which question the component thinks it asked: the signed-in person, their own
        // IN, refused by ClockIn when they already have an open period.
        if ($operator === null || $location === null) {
            return;
        }

        try {
            (new ClockIn)->handle($operator, $location, $operator);
            $this->clockPrompt = null;
            $this->clockFeedback = null;
            $this->dispatch('counter-clock-state', open: true);
            $this->flash(__('Entrada fichada: :time', ['time' => local_datetime(now(), 'H:i', $location)]), 'success');
        } catch (DomainException|InvalidArgumentException $e) {
            $this->clockFeedback = $e->getMessage();
        }
    }

    /** "Solo identificarme" — signed in, no clock event (a manager passing through). */
    public function skipClockIn(): void
    {
        $this->clockPrompt = null;
        $this->clockFeedback = null;
    }

    /** The forgotten clock-out: the person declares when the open period ended (reason required), then clocks in. */
    public function declareForgottenEnd(): void
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();
        $open = $operator !== null ? WorkedHours::openPeriodFor($operator) : null;

        // Only a period left open from an EARLIER business day can be declared here.
        if ($operator === null || $location === null || $open === null
            || $open->business_date->toDateString() >= BusinessDay::today($open->location)) {
            return;
        }

        try {
            // An empty or malformed time is the person's slip, not an error to echo: Carbon's own message is not for staff.
            $end = rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->declaredEnd, $open->location->timezone ?: 'Europe/Madrid'), null, false);
            if (! $end instanceof CarbonImmutable) {
                throw new InvalidArgumentException(__('Indica la hora a la que terminaste.'));
            }
            (new ClockOut)->handle($operator, $operator, StaffClockSource::SELF_DECLARED, $end, $this->declaredReason);
            (new ClockIn)->handle($operator, $location, $operator);
            $this->clockPrompt = null;
            $this->clockFeedback = null;
            $this->declaredEnd = '';
            $this->declaredReason = '';
            $this->dispatch('counter-clock-state', open: true);
            $this->flash(__('Salida declarada y entrada fichada.'), 'success');
        } catch (DomainException|InvalidArgumentException|AuthorizationException $e) {
            $this->clockFeedback = $e->getMessage();
        } catch (\Throwable) {
            $this->clockFeedback = __('Indica la hora a la que terminaste.');
        }
    }

    /**
     * "Fichar salida" from the operator menu — it asks for the PIN AGAIN: a tablet left signed in must not let somebody
     * else clock this person out. Browser-dispatched from the top bar (outside every component's DOM).
     */
    #[On('counter-clock-out')]
    public function beginClockOut(): void
    {
        $operator = CounterOperator::current();

        if ($operator === null) {
            return;
        }

        if (WorkedHours::openPeriodFor($operator) === null) {
            $this->flash(__('No tienes una jornada abierta.'), 'warning');

            return;
        }

        $this->operatorPin = '';
        $this->clockFeedback = null;
        $this->clockPrompt = 'out';
    }

    /** Cancels either clock PIN step (out, or 338's in). */
    public function cancelClockOut(): void
    {
        $this->clockPrompt = null;
        $this->clockFeedback = null;
        $this->operatorPin = '';
    }

    /**
     * The PIN for "Fichar salida": the same pad, the same UnlockOperator throttle — and it must be THIS person's PIN.
     *
     * @return array{ok: bool, name?: string}
     */
    public function confirmClockOut(): array
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();
        $pin = trim($this->operatorPin);
        $this->operatorPin = '';

        if ($operator === null || $location === null || $pin === '') {
            return ['ok' => false];
        }

        if (! $this->pinIsTheOperators($operator, $location, $pin, __('Ese PIN no es el tuyo: cada persona ficha su propia salida.'))) {
            return ['ok' => false];
        }

        try {
            (new ClockOut)->handle($operator, $operator, StaffClockSource::PIN);
        } catch (DomainException|InvalidArgumentException $e) {
            $this->clockFeedback = $e->getMessage();

            return ['ok' => false];
        }

        $this->clockPrompt = null;
        $this->clockFeedback = null;
        // This person has finished: lock, so the next person identifies themselves.
        $this->lockCounter();
        $this->dispatch('counter-lock');

        return ['ok' => true, 'name' => Str::before(trim((string) $operator->name), ' ')];
    }

    /**
     * Prompt 338 — "Fichar entrada" from the top bar, for a person working unclocked: their own PIN again (the same pad and
     * throttle as the clock-out), then a PIN clock-in at now at this sede. It never locks: they are starting, not leaving.
     */
    #[On('counter-clock-in')]
    public function beginClockIn(): void
    {
        $operator = CounterOperator::current();

        if ($operator === null) {
            return;
        }

        if (WorkedHours::openPeriodFor($operator) !== null) {
            $this->flash(__('Ya tienes la jornada abierta.'), 'warning');
            $this->dispatch('counter-clock-state', open: true);

            return;
        }

        $this->operatorPin = '';
        $this->clockFeedback = null;
        $this->clockPrompt = 'in-pin';
    }

    /** @return array{ok: bool, name?: string} */
    public function confirmClockIn(): array
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();
        $pin = trim($this->operatorPin);
        $this->operatorPin = '';

        if ($operator === null || $location === null || $pin === '') {
            return ['ok' => false];
        }

        if (! $this->pinIsTheOperators($operator, $location, $pin, __('Ese PIN no es el tuyo: cada persona ficha su propia entrada.'))) {
            return ['ok' => false];
        }

        try {
            (new ClockIn)->handle($operator, $location, $operator, StaffClockSource::PIN);
        } catch (DomainException|InvalidArgumentException|AuthorizationException $e) {
            $this->clockFeedback = $e->getMessage();

            return ['ok' => false];
        }

        $this->clockPrompt = null;
        $this->clockFeedback = null;
        $this->dispatch('counter-clock-state', open: true);

        return ['ok' => true, 'name' => Str::before(trim((string) $operator->name), ' ')];
    }

    /** The clock PIN: through UnlockOperator's throttle, and it must be THIS person's (each person clocks their own day). */
    private function pinIsTheOperators(User $operator, Location $location, string $pin, string $notYours): bool
    {
        $matched = (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());

        if ($matched === null) {
            $this->clockFeedback = $this->pinFailureMessage();

            return false;
        }

        if (! $matched->is($operator)) {
            $this->clockFeedback = $notYours;

            return false;
        }

        return true;
    }

    #[On('counter-my-hours')]
    public function openMyHours(): void
    {
        $this->myHoursOpen = CounterOperator::id() !== null;
    }

    public function closeMyHours(): void
    {
        $this->myHoursOpen = false;
    }

    /**
     * "Mis horas" — ONLY the signed-in person's own periods, this week and this month, whatever their role.
     *
     * @return array{periods: list<array<string, mixed>>, week_minutes: int, month_minutes: int, today_minutes: int, today_since: ?string}
     */
    public function myHours(): array
    {
        $operator = CounterOperator::current();
        $location = $this->resolveLocation();

        if ($operator === null || $location === null) {
            return ['periods' => [], 'week_minutes' => 0, 'month_minutes' => 0, 'today_minutes' => 0, 'today_since' => null];
        }

        $month = Period::thisMonth($location);
        $week = Period::thisWeek($location);
        $sedes = Location::query()->withoutGlobalScopes()->where('organisation_id', $location->organisation_id)->pluck('id')->all();
        $periods = WorkedHours::periods([$operator->id], $sedes, $month->start->setTimezone($location->timezone ?: 'Europe/Madrid')->startOfDay(), $month->end->setTimezone($location->timezone ?: 'Europe/Madrid')->startOfDay()->addDay());
        $weekFrom = BusinessDay::date($location, $week->start)->toDateString();

        // Prompt 341 — today's line: the minutes so far (an open period counts up to now) and since when.
        $todayDate = BusinessDay::today($location);
        $today = array_values(array_filter($periods, fn (array $p): bool => $p['business_date'] === $todayDate));
        $todayMinutes = array_sum(array_map(fn (array $p): int => $p['minutes'] ?? (int) $p['in']->occurred_at->diffInMinutes(now()), $today));
        $since = $today !== [] ? local_datetime(collect($today)->min(fn (array $p) => $p['in']->occurred_at), 'H:i', $location) : null;

        return [
            'periods' => array_reverse($periods),
            'week_minutes' => array_sum(array_map(fn (array $p): int => $p['business_date'] >= $weekFrom ? (int) $p['minutes'] : 0, $periods)),
            'month_minutes' => array_sum(array_map(fn (array $p): int => (int) $p['minutes'], $periods)),
            'today_minutes' => (int) $todayMinutes,
            'today_since' => $since,
        ];
    }

    /**
     * A ONE-OFF authorisation by someone else's PIN (prompt 265 — the "supervisor PIN"): the staff member serving
     * stays the operator of record and stays identified, the basket is untouched, and the person whose PIN it is
     * becomes the authoriser for this one act. The PIN is checked by the SAME `UnlockOperator` as the pad, against
     * this sede's staff and with the same sede-wide throttle — a wrong PIN counts towards the lockout. Returns the
     * authoriser only when they hold `$permission`; otherwise flashes why and returns null. It never touches
     * `CounterOperator`.
     */
    protected function authoriserFromPin(string $pin, string $permission): ?User
    {
        $location = $this->resolveLocation();
        $pin = trim($pin);

        if ($location === null || $pin === '') {
            $this->flash(__('Introduce el PIN de quien autoriza.'), 'error');

            return null;
        }

        $authoriser = (new UnlockOperator)->handle($location, $pin, $this->operatorThrottleKey());

        if ($authoriser === null) {
            $this->flash($this->pinFailureMessage(), 'error');

            return null;
        }

        if (! $authoriser->can($permission)) {
            $this->flash(__(':name no puede autorizar esta excepción.', ['name' => $authoriser->name]), 'error');

            return null;
        }

        return $authoriser;
    }

    /**
     * Guard a counter transaction: an operator must be PIN-identified first. Returns
     * false (and prompts to unlock) when none is — so the caller bails BEFORE writing,
     * never silently attributing the transaction to the device session user.
     */
    protected function requireOperator(): bool
    {
        if ($this->hasOperator()) {
            return true;
        }

        $this->operatorPanelOpen = true;
        $this->flash(__('Identifícate con tu PIN antes de continuar.'), 'error');

        return false;
    }

    /**
     * LOCATION-WIDE throttle bucket (prompt 120). Was per-device (…:sessionId), but a shared counter has many
     * devices and a browser session is trivial to rotate — so the count is keyed to the sede only, and every
     * device at that sede shares one escalating lockout. A wrong-sede/no-sede terminal buckets under 'none'.
     */
    /**
     * Prompt 286 — what a wrong PIN says, everywhere a PIN is entered: how many attempts are left before the lockout
     * ("PIN incorrecto. Te quedan 2 intentos."), or the lockout itself. Seeing that a wrong attempt costs something is
     * what stops the blind retyping.
     */
    protected function pinFailureMessage(): string
    {
        if ($this->operatorLockedOut()) {
            return __('Demasiados intentos. Espera un momento antes de reintentar.');
        }

        $left = (new UnlockOperator)->attemptsRemaining($this->resolveLocation(), $this->operatorThrottleKey());

        return trans_choice('PIN incorrecto. Te queda :count intento.|PIN incorrecto. Te quedan :count intentos.', $left, ['count' => $left]);
    }

    /** Prompt 286 — the lockout as live component state, so the pad's keys disable (and re-enable) with it. */
    public function dehydrateIdentifiesOperator(): void
    {
        $this->pinLocked = $this->operatorLockedOut();
    }

    private function operatorThrottleKey(): string
    {
        $locationId = app(ActiveScope::class)->locationId() ?? 'none';

        return 'counter-pin:'.$locationId;
    }
}
