<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Models\Dispensation;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\User;
use App\Support\MembershipCorrections;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Prompt 325 — *Anular* ONE membership entered by mistake (a duplicate, the wrong sede). Not the member's baja — that is
 * `App\Actions\Members\CancelMembership`, which cancels them all and records the departure.
 *
 * The row stays, as CANCELLED, with its reason; a cancelled membership no longer lets its socio be served at that sede
 * (eligibility reads ACTIVE memberships only). A PAID fee is never cancelled silently: there is no fee refund yet (Ben,
 * 325), so it is cancelled only as «Mantener la cuota pagada» — no money moves. Dispensations to the socio at that
 * sede in the last 30 days refuse it unless confirmed, so the registro stays coherent. Audited `membership.cancelled`.
 */
class CancelMembership
{
    public const RECENT_DAYS = 30;

    /** @throws AuthorizationException|DomainException */
    public function handle(Membership $membership, User $actor, string $reason, bool $keepPaidFee = false, bool $confirmRecentDispensations = false): Membership
    {
        MembershipCorrections::authorize($actor, $membership);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('Indica el motivo de la anulación.'));
        }
        if ($membership->status === MembershipStatus::CANCELLED) {
            throw new DomainException(__('Esta membresía ya está anulada.'));
        }

        $paidCents = self::paidCents($membership);
        if ($paidCents > 0 && ! $keepPaidFee) {
            throw new DomainException(__('La cuota está pagada: confirma que se mantiene pagada.'));
        }
        $recent = self::recentDispensations($membership);
        if ($recent > 0 && ! $confirmRecentDispensations) {
            throw new DomainException(trans_choice('Hay :count dispensación en esta sede en los últimos 30 días: confírmalo para anular.|Hay :count dispensaciones en esta sede en los últimos 30 días: confírmalo para anular.', $recent, ['count' => $recent]));
        }

        $before = ['status' => $membership->status->value];
        $membership->update(['status' => MembershipStatus::CANCELLED]);
        (new RecordAuditLog)->handle('membership.cancelled', $membership, $before, [
            'status' => MembershipStatus::CANCELLED->value, 'reason' => $reason,
            'kept_paid_fee' => $paidCents > 0, 'paid_cents' => $paidCents, 'recent_dispensations' => $recent,
        ]);

        return $membership;
    }

    /** Money actually taken for the fee (payments, not waivers). */
    public static function paidCents(Membership $membership): int
    {
        return (int) MembershipFeePayment::query()->where('membership_id', $membership->id)
            ->where('method', '!=', FeePaymentMethod::WAIVED->value)->sum('amount_cents');
    }

    /** The socio's completed dispensations at this sede in the last 30 days. */
    public static function recentDispensations(Membership $membership): int
    {
        return Dispensation::query()->withoutGlobalScopes()->where('member_id', $membership->member_id)
            ->where('location_id', $membership->location_id)->where('status', DispensationStatus::COMPLETED->value)
            ->where('dispensed_at', '>=', now()->subDays(self::RECENT_DAYS))->count();
    }
}
