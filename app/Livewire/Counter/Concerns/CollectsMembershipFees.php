<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Memberships\RecordFeePayment;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\TillSession;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ONE membership-fee collection model shared by the till screen and the Socios counter tab (prompt 127) —
 * so a fee can be collected wherever the member is standing without a second code path. `RecordFeePayment`
 * stays the single writer; this only holds the search/outstanding/owed state and the shared validation around
 * it, returning a result the host component flashes. Collecting a fee remains the only thing that clears
 * `unpaid_fee`, and a CASH fee still requires an open till (the drawer-reconciliation invariant) — enforced
 * here, once, for every caller.
 */
trait CollectsMembershipFees
{
    /** Org-wide member search to pick who's paying a membership fee. */
    public string $feeSearch = '';

    /** The held member id — their outstanding fee is resolved live, never stored. */
    public ?string $feeMemberId = null;

    /** The amount being collected, in euros (partial/instalment allowed). */
    public string $feeAmount = '';

    /** CASH (into the drawer) or WALLET (posts a FEE ledger movement — no drawer needed). */
    public string $feeMethod = 'CASH';

    public function selectFeeMember(string $memberId): void
    {
        $this->feeMemberId = $memberId;
        $this->feeSearch = '';
    }

    public function clearFeeMember(): void
    {
        $this->reset(['feeMemberId', 'feeSearch', 'feeAmount']);
        $this->feeMethod = 'CASH';
    }

    /**
     * The shared collection core: validate the amount against what's owed and post it through the single
     * writer. A CASH fee needs the open till ($session); a WALLET fee does not. Returns ['type' => success|
     * error, 'message' => ...] for the host to flash — no host-flash dependency, so it drops cleanly into any
     * counter screen.
     *
     * @return array{type: string, message: string}
     */
    protected function collectFeeThrough(?TillSession $session, Location $location, User $user): array
    {
        $member = $this->feeMemberId !== null ? Member::query()->find($this->feeMemberId) : null;
        if ($member === null) {
            return ['type' => 'error', 'message' => __('Selecciona un socio.')];
        }

        $membership = $this->outstandingMembership($member, $location);
        if ($membership === null) {
            return ['type' => 'error', 'message' => __('Este socio no tiene cuota pendiente en esta sede.')];
        }

        $owed = $this->owedCents($membership);
        $cents = $this->parseFeeCents();
        if ($cents === null || $cents <= 0) {
            return ['type' => 'error', 'message' => __('El importe no es válido.')];
        }
        if ($cents > $owed) {
            return ['type' => 'error', 'message' => __('El importe supera la cuota pendiente (:owed).', ['owed' => Money::fromCents($owed)->formatted()])];
        }

        $method = $this->feeMethod === 'WALLET' ? FeePaymentMethod::WALLET : FeePaymentMethod::CASH;

        // A CASH fee MUST land in the open drawer, or the arqueo is wrong at close — refuse it with a message,
        // the way a cash refund is (prompt 127). A wallet fee posts a ledger movement and needs no till.
        if ($method === FeePaymentMethod::CASH && $session === null) {
            return ['type' => 'error', 'message' => __('No hay caja abierta: un cobro en efectivo debe registrarse en la caja.')];
        }

        try {
            (new RecordFeePayment)->handle($membership, $cents, $method, [
                'till_session_id' => $session?->id,
                'operator_id' => CounterOperator::id() ?? $user->id,
            ]);
        } catch (DebtLimitExceededException $e) {
            return ['type' => 'error', 'message' => __('El monedero no admite el cargo: :reason', ['reason' => $e->getMessage()])];
        }

        $remaining = $owed - $cents;
        $this->reset(['feeMemberId', 'feeSearch', 'feeAmount']);
        $this->feeMethod = 'CASH';

        return [
            'type' => 'success',
            'message' => $remaining > 0
                ? __('Cuota parcial cobrada. Pendiente: :remaining', ['remaining' => Money::fromCents($remaining)->formatted()])
                : __('Cuota cobrada por completo.'),
        ];
    }

    /**
     * Inline collection for a member ALREADY on screen (the door verdict, the POS member card) — no search step.
     * Point the shared state at that member and, when no amount was typed, default to the FULL outstanding
     * balance, then run the SAME collectFeeThrough core. This is how the fee action follows the unpaid-fee
     * verdict wherever it is shown (prompt 127), with no second write path.
     *
     * @return array{type: string, message: string}
     */
    protected function collectInlineFeeFor(Member $member, ?TillSession $session, Location $location, User $user): array
    {
        $this->feeMemberId = $member->id;

        if (trim($this->feeAmount) === '') {
            $membership = $this->outstandingMembership($member, $location);
            $this->feeAmount = $membership !== null
                ? number_format($this->owedCents($membership) / 100, 2, '.', '')
                : '';
        }

        return $this->collectFeeThrough($session, $location, $user);
    }

    /** The member's outstanding membership at this sede (latest active with a balance), or null. */
    protected function outstandingMembership(Member $member, Location $location): ?Membership
    {
        $membership = $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first();

        return ($membership !== null && $this->owedCents($membership) > 0) ? $membership : null;
    }

    protected function owedCents(Membership $membership): int
    {
        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        return max(0, $membership->fee_cents->cents - $paid);
    }

    /**
     * Org-wide socio search — the SAME by-name/member_no query the dispensary POS uses.
     *
     * @return Collection<int, Member>|null
     */
    protected function feeSearchResults(): ?Collection
    {
        $term = trim($this->feeSearch);

        if (mb_strlen($term) < 2) {
            return null;
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

    /** Parse the euro amount field to integer cents (edge conversion), or null when blank/invalid. */
    protected function parseFeeCents(): ?int
    {
        $raw = str_replace(',', '.', trim($this->feeAmount));

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (int) round_half_up(((float) $raw) * 100);
    }
}
