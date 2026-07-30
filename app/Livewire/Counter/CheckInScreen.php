<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Attendance\CheckOutMember;
use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Members\ResolveMemberByToken;
use App\Enums\CheckInMethod;
use App\Enums\MembershipStatus;
use App\Exceptions\CheckInBlockedException;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberSanction;
use App\Models\Membership;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wallet;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

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
    /** Bound to the scan input — a keyboard-wedge scanner types the token then hits Enter. */
    public string $scan = '';

    /** Live org-wide fallback search (name or member number). */
    public string $search = '';

    /** The held socio (id only — the model is resolved live, never stored on the component). */
    public ?string $memberId = null;

    /** True when the held member arrived via a scanned card (records QR vs MANUAL). */
    public bool $scanned = false;

    /** The active location id, resolved in mount(). */
    public ?string $locationId = null;

    /** Friendly state when the operator has no location at all (still a 200). */
    public bool $noLocation = false;

    /** A blocked door check awaiting a manager override. */
    public bool $blocked = false;

    /** @var list<string> */
    public array $blockedReasons = [];

    /** Whether the override affordance (reason + authorise) is offered. */
    public bool $overrideOpen = false;

    public string $overrideReason = '';

    public ?string $flashMessage = null;

    /** success | warning | error */
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless($this->userCan('checkin.manage'), 403);

        $scope = app(ActiveScope::class);
        $this->locationId = $scope->locationId();

        // No active location yet: fall back to the operator's first assigned sede.
        if ($this->locationId === null) {
            $first = $this->currentUser()?->locations()->where('active', true)->orderBy('name')->first();

            if ($first !== null) {
                $scope->setLocation($first->id);
                $this->locationId = $first->id;
            }
        }

        $this->noLocation = $this->locationId === null;
    }

    // --- Scan & search ---------------------------------------------------------

    public function submitScan(): void
    {
        $token = trim($this->scan);
        $this->scan = '';

        if ($token === '') {
            return;
        }

        $member = (new ResolveMemberByToken)->handle($token);

        if ($member === null) {
            $this->flash(__('Tarjeta no reconocida. Inténtalo de nuevo o busca por nombre.'), 'error');

            return;
        }

        $this->holdMember($member->id, scanned: true);
    }

    public function selectMember(string $memberId): void
    {
        $this->holdMember($memberId, scanned: false);
    }

    public function clearMember(): void
    {
        $this->reset([
            'memberId', 'scanned', 'blocked', 'blockedReasons',
            'overrideOpen', 'overrideReason', 'search', 'flashMessage',
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
            $this->overrideOpen = $this->userCan('checkin.override');
            $this->flash($e->getMessage(), 'error');

            return;
        } catch (AuthorizationException) {
            $this->flash(__('No tienes permiso para autorizar una excepción.'), 'error');

            return;
        }

        $this->blocked = false;
        $this->overrideOpen = false;
        $this->overrideReason = '';
        $this->flash(__(':name ha entrado.', ['name' => $member->fullName()]), 'success');
        $this->dispatch('checkins-updated');
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

        if ($member !== null && $location !== null) {
            $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'door');
            $limits = (new ResolveMemberLimits)->handle($member, $location);
            $openCheckIn = $this->openCheckIn($member, $location);
            $membership = $this->activeMembership($member, $location);
            $walletCents = Wallet::balance($member->id, $location->id);
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
            'searchResults' => $this->searchResults(),
            'canOverride' => $this->userCan('checkin.override'),
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
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')
            ->first();
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
        if ($member->photo_path === null || $member->photo_path === '') {
            return null;
        }

        try {
            $disk = Storage::disk('documents');

            if (! $disk->exists($member->photo_path)) {
                return null;
            }

            return $disk->temporaryUrl($member->photo_path, now()->addSeconds((int) Settings::get('signed_url_ttl_seconds', 300)));
        } catch (Throwable) {
            return null; // local driver does not sign URLs — fall back to initials.
        }
    }

    /**
     * Org-wide socio search — deliberately crosses locations so the door can find a
     * member registered at another sede (Member is org-scoped, never location-scoped).
     *
     * @return Collection<int, Member>
     */
    private function searchResults(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            /** @var Collection<int, Member> $empty */
            $empty = new Collection;

            return $empty;
        }

        return Member::query()
            ->where(fn ($q) => $q
                ->where('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%')
                ->orWhere('member_no', 'like', '%'.$term.'%'))
            ->orderBy('last_name')
            ->limit(8)
            ->get();
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
        $this->search = '';
        $this->blocked = false;
        $this->blockedReasons = [];
        $this->overrideOpen = false;
        $this->overrideReason = '';
        $this->flashMessage = null;
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
