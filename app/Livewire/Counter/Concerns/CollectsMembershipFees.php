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

    /**
     * The waiver form (prompt 219): open, which reason, and the free text when it is `OTHER`.
     *
     * The owner: *"need an option to waive the fee — often they waive it if they are medical, or if they have
     * a membership at another club."* Today the only ways out of an outstanding fee are collecting it or a
     * manager quietly not chasing it, so a club that routinely waives shows members permanently "owing" money
     * it has decided not to take — and the door nags about it for ever.
     */
    public bool $waiveOpen = false;

    /** `THERAPEUTIC` · `OTHER_SEDE` · `OTHER` — see {@see self::waiveReasonOptions()}. */
    public string $waiveReason = '';

    public string $waiveReasonText = '';

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
     * @return array{type: string, message: ?string}
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
     * Forgo the outstanding fee — a recorded decision, through the SAME writer as a payment.
     *
     * `RecordFeePayment` with method `WAIVED`, so the debt clears everywhere `amount_cents` is summed: the
     * door notice, the `unpaid_fee` verdict, the fee panels, renewal. No edit to `fee_cents` — the club
     * charged €20 and chose to forgo it, which is a different fact from "the fee was €0", and the register
     * should hold the first one.
     *
     * **No open till required**, unlike a CASH fee: a waiver moves no cash and posts no wallet movement, so
     * the drawer has nothing to reconcile. The arqueo is untouched.
     *
     * Partial waivers fall out for free — waive €10 of €20 and the rest stays collectable — because it is
     * just another row against the same sum.
     *
     * @return array{type: string, message: ?string}
     */
    protected function waiveFeeThrough(Location $location, User $user): array
    {
        if (! $user->can('membership.fee.waive')) {
            return ['type' => 'error', 'message' => __('No tienes permiso para condonar cuotas.')];
        }

        $member = $this->feeMemberId !== null ? Member::query()->find($this->feeMemberId) : null;
        if ($member === null) {
            return ['type' => 'error', 'message' => __('Selecciona un socio.')];
        }

        $membership = $this->outstandingMembership($member, $location);
        if ($membership === null) {
            return ['type' => 'error', 'message' => __('Este socio no tiene cuota pendiente en esta sede.')];
        }

        $reason = $this->resolvedWaiveReason();

        // Refused HERE as well as at the writer: a reason is what turns forgoing income into a governance
        // record rather than a hole, and the UI disabling a button is not a rule.
        if ($reason === null) {
            return ['type' => 'error', 'message' => __('Indica el motivo de la condonación.')];
        }

        $owed = $this->owedCents($membership);
        $typed = $this->parseFeeCents();

        // Blank amount = the whole outstanding balance, which is the common case; a typed amount waives part.
        $cents = ($typed === null || $typed <= 0) ? $owed : $typed;

        if ($cents > $owed) {
            return ['type' => 'error', 'message' => __('El importe supera la cuota pendiente (:owed).', ['owed' => Money::fromCents($owed)->formatted()])];
        }

        (new RecordFeePayment)->handle($membership, $cents, FeePaymentMethod::WAIVED, [
            'operator_id' => CounterOperator::id() ?? $user->id,
            'reason' => $reason,
        ]);

        $remaining = $owed - $cents;
        $this->resetWaiveForm();
        $this->reset(['feeMemberId', 'feeAmount']);
        $this->feeMethod = 'CASH';

        // The amount is named because the field it was typed into has just been reset (prompt 202).
        // NO MESSAGE (prompt 234). The owner, on this exact toast: *"notifications should only be used if
        // really needed, and not cover the basket like this too."* A waive's outcome is visible the instant
        // it lands — the fee notice clears, 225's blocked surface gives the catalogue back, and a partial
        // waive leaves the panel showing the new balance. The RECORD is the audit row and the WAIVED payment,
        // which is what a waive is for; the toast only restated a screen the operator was already looking at.
        return ['type' => 'success', 'message' => null];
    }

    /**
     * Waive for a member ALREADY on screen — the door verdict and the POS member card.
     *
     * The same shape as `collectInlineFeeFor`: point the shared state at that member, then run the one core.
     * The hosts hold their member in `$memberId` and Socios in `$feeMemberId`, which is the same difference
     * prompt 211 had to bridge in `OpensMemberships` — and the same answer: bridge it in the concern, once.
     *
     * @return array{type: string, message: ?string}
     */
    protected function waiveInlineFeeFor(Member $member, Location $location, User $user): array
    {
        $this->feeMemberId = $member->id;

        return $this->waiveFeeThrough($location, $user);
    }

    /**
     * Flash a fee result — unless it deliberately has nothing to say (prompt 234).
     *
     * A success whose outcome the screen already shows is not information, and on this column it costs the
     * basket its height. `waiveFeeThrough()` returns a null message for exactly that reason; the type is still
     * returned so a caller can branch on success without a string to print.
     *
     * @param  array{type: string, message: ?string}  $result
     */
    protected function flashResult(array $result): void
    {
        if (($result['message'] ?? null) !== null) {
            $this->flash($result['message'], $result['type']);
        }
    }

    /** The reason as it will be stored, or null when the operator has not given one. */
    private function resolvedWaiveReason(): ?string
    {
        $option = collect($this->waiveReasonOptions())->firstWhere('value', $this->waiveReason);

        if ($option === null) {
            return null;
        }

        if ($option['value'] !== 'OTHER') {
            return (string) $option['label'];
        }

        $text = trim($this->waiveReasonText);

        return $text !== '' ? $text : null;
    }

    /**
     * The socio this concern's READ path acts on (prompt 229).
     *
     * Prompt 211 met exactly this host mismatch — the door and the POS hold their member in `$memberId`,
     * Socios in `$feeMemberId` — and bridged it in the concern with an overridable method. It did so for
     * `OpensMemberships`, and 219 bridged the fee ACTIONS the same way (`collectInlineFeeFor`,
     * `waiveInlineFeeFor` both point `$feeMemberId` at the member before running the core).
     *
     * What nobody bridged was the RENDER path. `waiveReasonOptions()` and `toggleWaive()`'s preselect run
     * before any action has happened, so on two of the three hosts they read a `$feeMemberId` that is still
     * null: the structured reasons never appeared on a fresh open, and only turned up after some *other*
     * action happened to set it — which is why the operator saw a different set of reasons depending on what
     * they had clicked first.
     *
     * A METHOD of this concern rather than a call into `OpensMemberships`: the two traits are used together
     * on all three hosts today, but a concern that reaches into another one's method is a coupling nothing
     * declares, and it would fatal on the first host that wanted fees without memberships.
     */
    protected function feeSubjectId(): ?string
    {
        return $this->feeMemberId;
    }

    /**
     * The reasons on offer — **structured, because the two common ones are data the system already holds**.
     *
     * A free-text box alone produces "ok" and "si". The therapeutic reason is offered when the member's own
     * `is_therapeutic` flag is set, and the other-sede reason when they hold an ACTIVE membership somewhere
     * else in this club (prompt 203's case, where the second fee is commonly forgone) — so the two routine
     * waivers are one tap, each already justified by the record. Everything else is free text, required
     * non-empty.
     *
     * @return list<array{value: string, label: string, suggested: bool}>
     */
    public function waiveReasonOptions(): array
    {
        $subjectId = $this->feeSubjectId();
        $member = $subjectId !== null ? Member::query()->find($subjectId) : null;
        $location = $this->resolveLocation();

        $therapeutic = (bool) ($member?->is_therapeutic);
        $elsewhere = $member !== null && $location !== null && $member->memberships()->withoutGlobalScopes()
            ->where('location_id', '!=', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->exists();

        $options = [];

        if ($therapeutic) {
            $options[] = ['value' => 'THERAPEUTIC', 'label' => __('Terapéutico'), 'suggested' => true];
        }

        if ($elsewhere) {
            $options[] = ['value' => 'OTHER_SEDE', 'label' => __('Socio en otra sede'), 'suggested' => true];
        }

        $options[] = ['value' => 'OTHER', 'label' => __('Otro motivo'), 'suggested' => false];

        return $options;
    }

    /** Open the waiver, pre-selecting the record-backed reason when there is exactly one obvious candidate. */
    public function toggleWaive(): void
    {
        $this->waiveOpen = ! $this->waiveOpen;

        if (! $this->waiveOpen) {
            $this->resetWaiveForm();

            return;
        }

        $suggested = collect($this->waiveReasonOptions())->firstWhere('suggested', true);
        $this->waiveReason = (string) ($suggested['value'] ?? '');
    }

    private function resetWaiveForm(): void
    {
        $this->waiveOpen = false;
        $this->waiveReason = '';
        $this->waiveReasonText = '';
    }

    /**
     * Inline collection for a member ALREADY on screen (the door verdict, the POS member card) — no search step.
     * Point the shared state at that member and, when no amount was typed, default to the FULL outstanding
     * balance, then run the SAME collectFeeThrough core. This is how the fee action follows the unpaid-fee
     * verdict wherever it is shown (prompt 127), with no second write path.
     *
     * @return array{type: string, message: ?string}
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
