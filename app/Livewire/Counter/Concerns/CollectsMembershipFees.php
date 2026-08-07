<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Memberships\RecordFeePayment;
use App\Enums\FeePaymentMethod;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\TillSession;
use App\Models\User;
use App\Support\CounterOperator;
use App\Support\Money;

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
    // Who is paying is now decided by the ONE shared lookup (prompt 194) — {@see FindsMembers}, whose
    // onMemberFound() calls selectFeeMember(). This concern used to carry a second member search of its own
    // ($feeSearch + feeSearchResults()), which is how the till and Socios each ended up with a name box that
    // could not resolve a scanned card.

    /** The held member id — their outstanding fee is resolved live, never stored. */
    public ?string $feeMemberId = null;

    /** The amount being collected, in euros (partial/instalment allowed). */
    public string $feeAmount = '';

    /** CASH (into the drawer) or WALLET (posts a FEE ledger movement — no drawer needed). */
    public string $feeMethod = 'CASH';

    public function selectFeeMember(string $memberId): void
    {
        $this->feeMemberId = $memberId;
    }

    public function clearFeeMember(): void
    {
        $this->reset(['feeMemberId', 'feeAmount']);
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
        $this->reset(['feeMemberId', 'feeAmount']);
        $this->feeMethod = 'CASH';

        // The amount is named because the field it was typed into has just been reset — a confirmation that
        // survives the figure it confirms is no confirmation at all (prompt 202).
        return [
            'type' => 'success',
            'message' => $remaining > 0
                ? __('Cuota cobrada: :amount. Pendiente: :remaining', ['amount' => Money::fromCents($cents)->formatted(), 'remaining' => Money::fromCents($remaining)->formatted()])
                : __('Cuota cobrada por completo: :amount.', ['amount' => Money::fromCents($cents)->formatted()]),
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
        $membership = $member->activeMembershipAt($location);

        return ($membership !== null && $this->owedCents($membership) > 0) ? $membership : null;
    }

    protected function owedCents(Membership $membership): int
    {
        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        return max(0, $membership->fee_cents->cents - $paid);
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
