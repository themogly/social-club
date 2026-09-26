<?php

namespace App\Livewire\Counter;

use App\Actions\Attendance\CheckOutMember;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Occupancy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The live "who's inside" panel: everyone currently checked in at the active
 * location, the aforo (occupancy) ring, per-row check-out and "check out all".
 * Everything is queried LIVE on every render (occupancy is transactional data and
 * is never cached), refreshed by poll and by the `checkins-updated` event the
 * check-in screen fires when it admits or releases a socio.
 */
class WhosInside extends Component
{
    public ?string $locationId = null;

    public function mount(): void
    {
        abort_unless($this->deviceCan('checkin.manage'), 403);

        $this->locationId = app(ActiveScope::class)->locationId();
    }

    public function checkOut(string $checkInId): void
    {
        abort_unless($this->operatorCan('checkin.manage'), 403);

        // Scoped to THIS location by construction — a session at another sede is
        // out of reach (authorisation, not just a filter).
        $checkIn = CheckIn::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->whereNull('checked_out_at')
            ->find($checkInId);

        if ($checkIn !== null) {
            (new CheckOutMember)->handle($checkIn);
            $this->dispatch('checkins-updated');
        }
    }

    public function checkOutAll(): void
    {
        abort_unless($this->operatorCan('checkin.manage'), 403);

        $location = $this->resolveLocation();

        if ($location === null) {
            return;
        }

        CheckIn::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->whereNull('checked_out_at')
            ->get()
            ->each(fn (CheckIn $checkIn) => (new CheckOutMember)->handle($checkIn));

        $this->dispatch('checkins-updated');
    }

    /** The check-in screen fires this after an admit/release; re-render reads live. */
    #[On('checkins-updated')]
    public function refreshPanel(): void
    {
        // No state to reset — render() always re-queries. This just triggers it.
    }

    public function render(): View
    {
        $location = $this->resolveLocation();
        $fraction = $location !== null ? Occupancy::fraction($location) : null;

        return view('livewire.counter.whos-inside', [
            'location' => $location,
            'checkIns' => $this->openCheckIns($location),
            'current' => $location !== null ? Occupancy::current($location) : 0,
            'capacity' => $location !== null ? Occupancy::capacity($location) : null,
            'fraction' => $fraction,
            'state' => $this->aforoState($fraction),
        ]);
    }

    /** @return Collection<int, CheckIn> */
    private function openCheckIns(?Location $location): Collection
    {
        return CheckIn::query()->withoutGlobalScopes()
            ->with('member')
            ->where('location_id', $location?->id)
            ->whereNull('checked_out_at')
            ->orderByDesc('checked_in_at')
            ->get();
    }

    /** ok | near | at | none — colour is always paired with the current/capacity number. */
    private function aforoState(?float $fraction): string
    {
        if ($fraction === null) {
            return 'none';
        }

        return match (true) {
            $fraction >= 1.0 => 'at',
            $fraction >= 0.85 => 'near',
            default => 'ok',
        };
    }

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    /** May the tablet's login show this list at all? The mount gate — see IdentifiesOperator::deviceUser(). */
    private function deviceCan(string $permission): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can($permission) : false;
    }

    /**
     * Checking someone out is the PIN operator's act (prompt 255): asked of the operator, never the tablet's
     * login, and refused with nobody identified. This child has no PIN pad of its own — the host screen's is
     * the one pad (173) — so a refusal here is a plain 403.
     */
    private function operatorCan(string $permission): bool
    {
        return CounterOperator::current()?->can($permission) ?? false;
    }
}
