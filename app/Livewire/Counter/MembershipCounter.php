<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
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
use Livewire\Component;

/**
 * Socios — the counter tab for membership (prompt 127). The deliberate-visit case: find a member, see their
 * membership state (tier, expiry, what is owed) and collect a fee. It is a THIN shell over the SAME shared
 * fee-collection concern the till screen uses ({@see CollectsMembershipFees} → RecordFeePayment, the single
 * writer) — no second path — so a fee taken here produces byte-identical records and clears `unpaid_fee` just
 * the same. A CASH fee still lands in the open drawer; a wallet fee does not need one. Owner deliberately kept
 * SMALL: collect a fee and see what's owed. Renewals, tier changes, suspensions and limits stay in the admin
 * panel where they carry real authorisation weight and do not belong on a counter tablet.
 *
 * Gated on `membership.fee.collect` (the same permission, unchanged). Layout + operator identification are the
 * shared counter chrome.
 */
#[Layout('components.layouts.counter')]
class MembershipCounter extends Component
{
    use CollectsMembershipFees, IdentifiesOperator, ResolvesCounterLocation, SignsUpMembers;

    /** How many past collections the counter will show. A counter answers a question; it is not an export. */
    private const HISTORY_LIMIT = 5;

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

    public function mount(): void
    {
        abort_unless($this->userCan('membership.fee.collect'), 403);
        $this->resolveCounterLocation();
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
        $this->flash($result['message'], $result['type']);
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
            'feeResults' => $this->feeSearchResults(),
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
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first();
    }

    private function openTill(Location $location): ?TillSession
    {
        return TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', TillSessionStatus::OPEN->value)
            ->latest('opened_at')->first();
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
}
