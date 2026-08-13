<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\SelectTillSession;
use App\Enums\DashboardAlert;
use App\Enums\DispensationStatus;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\FindsMembers;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\OpensMemberships;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Livewire\Counter\Concerns\SignsUpMembers;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Money;
use App\Support\VaultUrl;
use App\Support\Wallet;
use App\Support\Weight;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Socios — the counter tab for membership (prompt 127). The deliberate-visit case: find a member, see their
 * membership state (tier, expiry, what is owed) and collect a fee. It is a THIN shell over the SAME shared
 * fee-collection concern the till screen uses ({@see CollectsMembershipFees} → RecordFeePayment, the single
 * writer) — no second path — so a fee taken here produces byte-identical records and clears `unpaid_fee` just
 * the same. A CASH fee still lands in the open drawer; a wallet fee does not need one.
 *
 * **Prompt 203 moved one line, deliberately, and left the rest.** 127 kept this screen to "collect a fee and
 * see what's owed", with renewals in the admin panel "where they carry real authorisation weight". That held
 * until the screen started telling operators to do something they could not do: an ACTIVE member with no
 * membership at this sede read *"renew their fee from their record"*, and the record is in a panel STAFF hold
 * no permission to act in. So opening a membership AT THE SEDE YOU ARE WORKING AT, on the tier's default fee,
 * is now here ({@see OpensMemberships}, gated on `membership.enrol`) — the same shape prompt 174 used for the
 * alta: the audited, single-writer, locally-scoped route is the open one. Fee overrides, tier changes,
 * suspensions, limits and transfers between sedes have NOT moved.
 *
 * Gated on `membership.fee.collect` (the same permission, unchanged). Layout + operator identification are the
 * shared counter chrome.
 */
#[Layout('components.layouts.counter')]
class MembershipCounter extends Component
{
    use CollectsMembershipFees, FindsMembers, IdentifiesOperator, OpensMemberships, ResolvesCounterLocation, SignsUpMembers, WithFileUploads;

    /** How many past collections the counter will show. A counter answers a question; it is not an export. */
    private const HISTORY_LIMIT = 5;

    /**
     * Prompt 194 — the shared lookup found somebody; Socios' job is to put them on screen.
     *
     * This tab used to offer a name box with no scan affordance at all, which is worse than it sounds: a USB
     * wedge reader types into whatever has focus and presses Enter, so a card scanned here ran a name search
     * for a 48-character token and found nothing. It now resolves the token first, exactly like the door.
     */
    protected function onMemberFound(Member $member, bool $scanned): void
    {
        $this->selectFeeMember($member->id);
    }

    /** The active location id, resolved in mount(). #[Locked] (prompt 75): the client can never retarget the sede. */
    #[Locked]
    public ?string $locationId = null;

    public bool $noLocation = false;

    /**
     * Whether the itemised collection history is on screen (prompt 177).
     *
     * Closed by default, and it is a privacy decision rather than a layout one: what a named person
     * collected is Article 9 data, on a tablet in a room with the next socio standing behind them. The
     * SUMMARY (this month against their limit) is enough to answer the usual question and identifies
     * nothing on its own; the itemised list is one deliberate tap away and closes again the moment the
     * member is cleared. The idle lock (prompt 120) covers the abandoned-tablet case; this covers the
     * queue.
     */
    public bool $showHistory = false;

    /** The socio the history was opened for — see historyIsForCurrentMember(). */
    #[Locked]
    public ?string $historyForMemberId = null;

    public ?string $flashMessage = null;

    /** success | warning | error */
    public string $flashType = 'success';

    /**
     * Which hub alert sent the operator here, if any (prompt 207).
     *
     * The hub's *Requiere atención* rail used to link to this SCREEN, which is an empty search box: *"1
     * membresía vence pronto"* told the operator something was wrong and then asked them to guess who. It
     * links here with the alert attached now, and the screen opens on that alert's subjects.
     *
     * Not `#[Locked]`, deliberately: it is a read-only filter over rows this screen may already show, and
     * every row it produces goes through the same sede scope and the same `selectMember()` as a typed search.
     * There is nothing here a hostile client could reach that a search box would not also reach.
     */
    #[Url(as: 'alert', except: null)]
    public ?string $alert = null;

    /** A counter answers a question; it is not an export (177's rule, same figure). */
    private const WORKLIST_LIMIT = 10;

    public function mount(): void
    {
        abort_unless($this->userCan('membership.fee.collect'), 403);
        $this->resolveCounterLocation();

        // Pending applications already had a home on this screen — the Alta panel and its
        // `pendingAltaApplications()` list (174). Arriving from that alert opens it rather than building a
        // second list of the same rows beside it.
        if ($this->alert === DashboardAlert::PENDING_APPLICATIONS->value && $this->userCan('applications.review')) {
            $this->altaOpen = true;
        }
    }

    /**
     * The subjects behind the alert that sent the operator here — resolved HERE, on arrival, not carried in
     * the alert.
     *
     * `Dashboard::alerts()` gives a count and nothing else, and that is deliberate: the hub is on display in
     * a room with the next socio standing behind the current one, so 177's rule is counts and states, never
     * names. The names belong on this screen, which already shows member data and is where the operator is
     * about to act.
     *
     * @return array{title: string, rows: list<array{member_id: string, name: string, detail: string}>, shown: int, total: int}|null
     */
    public function worklist(): ?array
    {
        $location = $this->resolveLocation();
        $alert = DashboardAlert::tryFrom((string) $this->alert);

        if ($location === null || $alert === null) {
            return null;
        }

        $rows = match ($alert) {
            DashboardAlert::MEMBERSHIPS_EXPIRING => $this->expiringMembershipRows($location),
            DashboardAlert::MEMBERS_OVER_LIMIT => $this->overLimitRows($location),
            // Everything else either has its own surface on this screen (the Alta panel, opened in mount)
            // or has no subject on the counter at all — see DashboardAlert::counterRoute().
            default => collect(),
        };

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'title' => $alert->label($rows->count()),
            'rows' => $rows->take(self::WORKLIST_LIMIT)->values()->all(),
            'shown' => min($rows->count(), self::WORKLIST_LIMIT),
            'total' => $rows->count(),
        ];
    }

    /**
     * Memberships in the renewal window at THIS sede, through the one shared scope the dashboard counts with
     * ({@see Membership::scopeExpiringSoon}) — so the number in the rail and the names here are one set.
     *
     * @return Collection<int, array{member_id: string, name: string, detail: string}>
     */
    private function expiringMembershipRows(Location $location): Collection
    {
        return Membership::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->expiringSoon()
            ->with('member')
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (Membership $m): bool => $m->member !== null)
            ->map(fn (Membership $m): array => [
                'member_id' => (string) $m->member_id,
                'name' => (string) $m->member?->fullName(),
                'detail' => (string) __('Vence el :date', ['date' => $m->expires_at?->translatedFormat('j M Y') ?? '—']),
            ])->values();
    }

    /**
     * Members carrying a per-member monthly override who have reached it.
     *
     * Resolved through `ResolveMemberLimits` — **the resolver this screen already uses** for the allowance
     * block (177: if a figure here ever disagrees with the dispensary, this screen is wrong and the resolver
     * is right). Not a second copy of `Dashboard::membersOverLimit()`'s aggregate, which exists because the
     * dashboard needs one number over the whole org; here the candidate set is only the members who carry an
     * override, which is small by definition.
     *
     * Org-wide, like the alert itself and like the counter's own member search (194/203): a socio who has run
     * out is a fact about them, not about the sede they last collected at.
     *
     * @return Collection<int, array{member_id: string, name: string, detail: string}>
     */
    private function overLimitRows(Location $location): Collection
    {
        $resolver = new ResolveMemberLimits;

        return Member::query()
            ->whereNotNull('monthly_limit_cg')->where('monthly_limit_cg', '>', 0)
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function (Member $member) use ($resolver, $location): ?array {
                $limits = $resolver->handle($member, $location);
                $used = $limits->monthlyUsedCg;
                $cap = $limits->monthlyLimitCg;

                if ($cap <= 0 || $used < $cap) {
                    return null;
                }

                return [
                    'member_id' => (string) $member->id,
                    'name' => $member->fullName(),
                    'detail' => (string) __(':used de :limit este mes', ['used' => $this->grams($used), 'limit' => $this->grams($cap)]),
                ];
            })
            ->filter()
            ->values();
    }

    public function collectFee(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        $user = $this->currentUser();
        if ($user === null || ! $user->can('membership.fee.collect')) {
            $this->flash(__('No tienes permiso para cobrar cuotas.'), 'error');

            return;
        }

        $location = $this->resolveLocation();
        if ($location === null) {
            $this->flash(__('Sin sede activa.'), 'error');

            return;
        }

        // The open drawer at this sede, if any — a CASH fee needs it (collectFeeThrough refuses one without);
        // a wallet fee does not, which is why the Socios tab can take a fee with no till open.
        $result = $this->collectFeeThrough($this->openTill($location), $location, $user);
        $this->flashResult($result);
    }

    public function render(): View
    {
        $this->applyCounterScope();
        $location = $this->resolveLocation();

        $feeMember = $this->feeMemberId !== null ? Member::query()->find($this->feeMemberId) : null;
        $membership = ($feeMember !== null && $location !== null) ? $this->latestMembership($feeMember, $location) : null;

        // Prompt 177 — READING, added to a screen that could already collect. Every figure comes from the
        // resolver that already owns it, so if one of these ever disagrees with the dispensary the SCREEN is
        // wrong, not the resolver. No second read model.
        $verdict = null;
        $limits = null;

        if ($feeMember !== null && $location !== null) {
            $verdict = (new ResolveMemberEligibility)->handle($feeMember, $location, 'counter');
            $limits = (new ResolveMemberLimits)->handle($feeMember, $location);
        }

        return view('livewire.counter.membership-counter', [
            'location' => $location,
            'openTill' => $location !== null ? $this->openTill($location) : null,
            'feeMember' => $feeMember,
            'membership' => $membership,
            'owedCents' => $membership !== null ? $this->owedCents($membership) : null,
            'verdict' => $verdict,
            'limits' => $limits,
            'walletCents' => ($feeMember !== null && $location !== null) ? Wallet::balance($feeMember->id, $location->id) : 0,
            'photoUrl' => $feeMember !== null ? $this->photoUrl($feeMember) : null,
            'recent' => ($feeMember !== null && $location !== null && $this->historyIsForCurrentMember())
                ? $this->recentDispensations($feeMember, $location)
                : null,
            // Prompt 203 — which of the three dead-end situations this member is in, and the register facts
            // the operator needs to tell them apart. Resolved here so the blade branches on a word.
            'membershipCase' => ($feeMember !== null && $location !== null)
                ? $this->membershipCase($feeMember, $location)
                : null,
            'lapsedHere' => ($feeMember !== null && $location !== null)
                ? $this->lapsedMembershipHere($feeMember, $location)
                : null,
            'elsewhere' => ($feeMember !== null && $location !== null)
                ? $this->membershipsElsewhere($feeMember, $location)
                : collect(),
            'openTiers' => $location !== null ? $this->openTiers($location) : collect(),
        ]);
    }

    /** Show or hide the itemised history. Closed again whenever the member changes — see $showHistory. */
    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
        $this->historyForMemberId = $this->showHistory ? $this->feeMemberId : null;
    }

    /**
     * The history is bound to the socio it was opened for, and shown only while they are still the socio on
     * screen.
     *
     * Deliberately NOT a `updatedFeeMemberId()` hook: that fires for client-side model updates, and
     * `selectFeeMember()` sets the property in PHP, so the hook would silently not run on the main path.
     * Deliberately not an override of the shared concern's methods either — three code paths assign
     * `$feeMemberId` and an override would have to catch all of them. Binding the disclosure to an id
     * cannot be got wrong by a future caller: one socio's collections can never be on screen while the
     * next one is being served.
     */
    public function historyIsForCurrentMember(): bool
    {
        return $this->showHistory
            && $this->historyForMemberId !== null
            && $this->historyForMemberId === $this->feeMemberId;
    }

    /**
     * The socio's recent collections at this sede. READ-ONLY, and capped: this answers "what did I have and
     * when", not "give me an export". COMPLETED only — a voided dispensation did not happen, and showing one
     * would tell a socio they collected something they did not.
     *
     * @return Collection<int, Dispensation>
     */
    private function recentDispensations(Member $member, Location $location): Collection
    {
        return Dispensation::query()->withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('location_id', $location->id)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->with('lines')
            ->latest('created_at')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /** Encrypted photo → the authorised, access-logged endpoint only (prompt 113). Null → initials fallback. */
    private function photoUrl(Member $member): ?string
    {
        $actor = Auth::user();

        return $actor instanceof User ? VaultUrl::photo($member, $actor) : null;
    }

    /** The member's latest active membership at this sede (whether or not anything is owed) — for the summary. */
    private function latestMembership(Member $member, Location $location): ?Membership
    {
        return $member->activeMembershipAt($location);
    }

    /** Through the ONE resolver — see CheckInScreen::openTill(); this screen had the same divergent copy. */
    private function openTill(Location $location): ?TillSession
    {
        return (new SelectTillSession)->handle($location);
    }

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function userCan(string $permission): bool
    {
        return $this->currentUser()?->can($permission) ?? false;
    }

    /** Weight for display (integer centigrams), via the shared value object — same formatter as the POS. */
    public function grams(int $centigrams): string
    {
        return Weight::fromCentigrams($centigrams)->formatted();
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->formatted();
    }

    protected function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    /**
     * Forgo this member's outstanding fee (prompt 219) — the shared concern does the work.
     *
     * A thin resolve-and-call, like `collectFee`: the rule, the reason and the audit live in
     * {@see CollectsMembershipFees::waiveFeeThrough}, so all three hosts
     * behave identically and there is one place to read.
     */
    public function waiveFee(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        $location = $this->resolveLocation();
        $user = $this->currentUser();

        if ($location === null || $user === null) {
            $this->flash(__('Sin sede activa.'), 'error');

            return;
        }

        $result = $this->waiveFeeThrough($location, $user);
        $this->flashResult($result);
    }
}
