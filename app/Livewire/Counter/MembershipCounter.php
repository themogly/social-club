<?php

namespace App\Livewire\Counter;

use App\Enums\MembershipStatus;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
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
    use CollectsMembershipFees, IdentifiesOperator, ResolvesCounterLocation;

    /** The active location id, resolved in mount(). #[Locked] (prompt 75): the client can never retarget the sede. */
    #[Locked]
    public ?string $locationId = null;

    public bool $noLocation = false;

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

        return view('livewire.counter.membership-counter', [
            'location' => $location,
            'openTill' => $location !== null ? $this->openTill($location) : null,
            'feeResults' => $this->feeSearchResults(),
            'feeMember' => $feeMember,
            'membership' => $membership,
            'owedCents' => $membership !== null ? $this->owedCents($membership) : null,
        ]);
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
