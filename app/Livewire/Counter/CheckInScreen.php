<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Attendance\CheckOutMember;
use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\SelectTillSession;
use App\Enums\CheckInMethod;
use App\Exceptions\CheckInBlockedException;
use App\Livewire\Counter\Concerns\CollectsMembershipFees;
use App\Livewire\Counter\Concerns\FindsMembers;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberSanction;
use App\Models\Membership;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use App\Support\VaultUrl;
use App\Support\Wallet;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The tablet-first check-in counter (the "door") — a full-page Livewire component
 * on its own authenticated route, NOT inside the Filament panel. A keyboard-wedge
 * QR scanner (or manual entry / org-wide name search) resolves a socio; the screen
 * shows their card, consumption gauge, wallet and the door eligibility verdict from
 * the ONE shared resolver, then admits them — or, for a `checkin.override` holder,
 * overrides a blocked door (always logged inside CheckInMember).
 *
 * Thin by design: every decision delegates to an Action/Support class and every
 * figure is queried live (never cached — occupancy, limits and balances are
 * transactional). This is the reference pattern the dispensary + bar POS copy.
 */
#[Layout('components.layouts.counter')]
class CheckInScreen extends Component
{
    use CollectsMembershipFees, FindsMembers, IdentifiesOperator, ResolvesCounterLocation;

    // The ONE lookup field ($lookup) and everything behind it live in FindsMembers (prompt 194) — this screen
    // used to stack a scan box above a name box, each already accepting what the other asked for.

    /** The held socio (id only — the model is resolved live, never stored on the component). */
    public ?string $memberId = null;

    /** True when the held member arrived via a scanned card (records QR vs MANUAL). */
    public bool $scanned = false;

    /**
     * The active location id, resolved in mount() from the operator's OWN available sedes. #[Locked]
     * (prompt 75) so the client can NEVER retarget it to another sede — the booted hook re-applies this
     * value as the request scope on every request, so an unlocked property would be a cross-sede write hole.
     */
    #[Locked]
    public ?string $locationId = null;

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    /** A blocked door check awaiting a manager override. */
    public bool $blocked = false;

    /** @var list<string> */
    public array $blockedReasons = [];

    public string $overrideReason = '';

    public ?string $flashMessage = null;

    /** success | warning | error */
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless($this->userCan('checkin.manage'), 403);

        // Resolve the counter's OWN working sede (session key counter.location_id) — never the panel
        // scope, never a silent guess. One assigned sede is adopted; several ⇒ ask (mustChooseLocation).
        $this->resolveCounterLocation();
    }

    // --- Scan & search ---------------------------------------------------------

    /** Prompt 194 — the shared lookup found somebody; the door's job is to hold them for a verdict. */
    protected function onMemberFound(Member $member, bool $scanned): void
    {
        $this->holdMember($member->id, $scanned);
    }

    public function clearMember(): void
    {
        $this->reset([
            'memberId', 'scanned', 'blocked', 'blockedReasons',
            'overrideReason', 'lookup', 'lookupSearched', 'flashMessage',
        ]);
    }

    // --- Admit / override / check out ------------------------------------------

    public function checkIn(): void
    {
        $this->attemptCheckIn(override: false);
    }

    public function confirmOverride(): void
    {
        $this->attemptCheckIn(override: true);
    }

    public function checkOut(): void
    {
        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        if ($member === null || $location === null) {
            return;
        }

        $open = $this->openCheckIn($member, $location);

        if ($open === null) {
            return;
        }

        (new CheckOutMember)->handle($open);
        $this->flash(__(':name ha salido.', ['name' => $member->fullName()]), 'success');
        $this->dispatch('checkins-updated');
    }

    private function attemptCheckIn(bool $override): void
    {
        $member = $this->resolveMember();
        $location = $this->resolveLocation();

        if ($member === null || $location === null) {
            return;
        }

        // Attribution: a PIN-identified operator is required — never the device session user.
        if (! $this->requireOperator()) {
            return;
        }

        $options = ['method' => $this->scanned ? CheckInMethod::QR : CheckInMethod::MANUAL];

        if ($override) {
            $user = $this->currentUser();

            if ($user === null || ! $user->can('checkin.override')) {
                $this->flash(__('No tienes permiso para autorizar una excepción.'), 'error');

                return;
            }

            $reason = trim($this->overrideReason);
            $options['override'] = true;
            $options['override_by'] = $user;
            $options['override_reason'] = $reason === '' ? null : $reason;
        }

        try {
            (new CheckInMember)->handle($member, $location, $options);
        } catch (CheckInBlockedException $e) {
            $this->blocked = true;
            $this->blockedReasons = (new ResolveMemberEligibility)->handle($member, $location, 'door')->blockingMessages();
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para autorizar una excepción.'), 'error');

            return;
        }

        $this->blocked = false;
        $this->overrideReason = '';
        $this->flash(__(':name ha entrado.', ['name' => $member->fullName()]), 'success');
        $this->dispatch('checkins-updated');
    }

    /**
     * Collect the held member's outstanding fee inline, right where the door flagged it (prompt 127) — the SAME
     * shared concern the till and Socios tab use, so it is one write path. A CASH fee needs the open drawer; a
     * WALLET fee does not. On success the door verdict re-resolves and the unpaid_fee flag clears itself.
     */
    public function collectMemberFee(): void
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
        $member = $this->resolveMember();
        if ($location === null || $member === null) {
            return;
        }

        $result = $this->collectInlineFeeFor($member, $this->openTill($location), $location, $user);
        $this->flash($result['message'], $result['type']);
    }

    // --- View data (assembled here; the view stays declarative) ----------------

    public function render(): View
    {
        $location = $this->resolveLocation();
        $member = $this->resolveMember();

        $verdict = null;
        $limits = null;
        $openCheckIn = null;
        $membership = null;
        $walletCents = 0;
        $openTill = null;

        if ($member !== null && $location !== null) {
            $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'door');
            $limits = (new ResolveMemberLimits)->handle($member, $location);
            $openCheckIn = $this->openCheckIn($member, $location);
            $membership = $this->activeMembership($member, $location);
            $walletCents = Wallet::balance($member->id, $location->id);
            $openTill = $this->openTill($location);
        }

        return view('livewire.counter.check-in-screen', [
            'location' => $location,
            'member' => $member,
            'verdict' => $verdict,
            'limits' => $limits,
            'openCheckIn' => $openCheckIn,
            'membership' => $membership,
            'sanction' => $member !== null ? $this->activeSanction($member) : null,
            'walletCents' => $walletCents,
            'photoUrl' => $member !== null ? $this->photoUrl($member) : null,
            'canOverride' => $this->userCan('checkin.override'),
            'cameraScanEnabled' => (bool) Settings::get('camera_scan_enabled', false),
            // Inline fee (prompt 127): the action follows the unpaid-fee verdict. Owed>0 iff the door flags it.
            'canCollectFee' => $this->userCan('membership.fee.collect'),
            'feeOwedCents' => $membership !== null ? $this->owedCents($membership) : 0,
            'openTillPresent' => $openTill !== null,
        ]);
    }

    /** Grams for display (integer centigrams ÷ 100, locale-aware) — never a float in storage. */
    public function grams(int $centigrams): string
    {
        return Weight::fromCentigrams($centigrams)->formatted();
    }

    /** Money for display (integer cents), via the shared value object. */
    public function money(int $cents): string
    {
        return Money::fromCents($cents)->formatted();
    }

    // --- Resolvers (live queries; nothing cached) ------------------------------

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    private function resolveMember(): ?Member
    {
        return $this->memberId !== null ? Member::query()->find($this->memberId) : null;
    }

    /**
     * Any open till at this sede — a CASH inline fee needs one; a WALLET fee does not (prompt 127).
     *
     * Through the ONE resolver (code-style audit). This screen used to take `latest('opened_at')` — the
     * NEWEST open session — while the two POS screens took the oldest; on a two-till sede that was a
     * different drawer for the same fee. The door has no terminal, so it takes the shared fallback.
     */
    private function openTill(Location $location): ?TillSession
    {
        return (new SelectTillSession)->handle($location);
    }

    private function openCheckIn(Member $member, Location $location): ?CheckIn
    {
        return CheckIn::query()->withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('location_id', $location->id)
            ->whereNull('checked_out_at')
            ->first();
    }

    private function activeMembership(Member $member, Location $location): ?Membership
    {
        return $member->activeMembershipAt($location);
    }

    private function activeSanction(Member $member): ?MemberSanction
    {
        $today = now()->toDateString();

        return $member->sanctions()
            ->whereDate('from_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('until_date')->orWhereDate('until_date', '>=', $today))
            ->latest('from_date')
            ->first();
    }

    /**
     * A short-lived signed URL to the member's photo on the PRIVATE documents disk,
     * or null (→ initials) when absent or the disk can't sign URLs (local dev).
     */
    private function photoUrl(Member $member): ?string
    {
        // The encrypted photo is served ONLY through the authorised, access-logged endpoint (prompt 113) —
        // never a bare disk temporaryUrl to the raw file. Null → initials when there is no photo or no user.
        $actor = Auth::user();

        return $actor instanceof User ? VaultUrl::photo($member, $actor) : null;
    }

    private function userCan(string $permission): bool
    {
        return $this->currentUser()?->can($permission) ?? false;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function holdMember(string $memberId, bool $scanned): void
    {
        $this->memberId = $memberId;
        $this->scanned = $scanned;
        $this->blocked = false;
        $this->blockedReasons = [];
        $this->overrideReason = '';
        $this->flashMessage = null;
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
