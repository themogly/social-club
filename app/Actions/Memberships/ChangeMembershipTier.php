<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\User;
use App\Support\MembershipCorrections;
use App\Support\Money;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 325 — *Cambiar tarifa*. The tier's limits and discounts apply from now on; nothing already dispensed changes.
 *
 * The fee: while some of it is still OWED, what is owed becomes the new tier's fee (or a typed amount, with
 * `membership.fee.override`, exactly as enrolment does) — never below what has already been paid. Once it is fully
 * settled (paid or waived), the fee stays as it is: the tier changes, the difference is only REPORTED, and any money is
 * the till's business. No money moves here. Audited `membership.tier.changed` with the old and new tier and fee.
 *
 * @phpstan-type Outcome array{fee_kept: bool, difference_cents: int}
 */
class ChangeMembershipTier
{
    /**
     * @return Outcome
     *
     * @throws AuthorizationException|DomainException
     */
    public function handle(Membership $membership, MembershipTier $tier, User $actor, string $reason, ?int $feeCents = null): array
    {
        $membership->assertNotCovered(); // prompt 348 — a linked membership is re-tiered through its home one
        MembershipCorrections::authorize($actor, $membership);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('Indica el motivo del cambio.'));
        }
        if ($membership->status === MembershipStatus::CANCELLED) {
            throw new DomainException(__('Esta membresía está anulada.'));
        }
        if ($tier->id === $membership->tier_id) {
            throw new DomainException(__('La membresía ya tiene esta tarifa.'));
        }

        return DB::transaction(function () use ($membership, $tier, $actor, $reason, $feeCents): array {
            $locked = Membership::query()->withoutGlobalScopes()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $before = ['tier_id' => $locked->tier_id, 'fee_cents' => $locked->fee_cents->cents];
            $owed = $locked->owedCents();
            $default = $tier->default_fee_cents->cents;
            $update = ['tier_id' => $tier->id];

            if ($owed > 0) {
                $newFee = $feeCents ?? $default;
                $overridden = $newFee !== $default;
                if ($overridden && ! $actor->can('membership.fee.override')) {
                    throw new AuthorizationException(__('Cambiar el importe de la cuota requiere permiso.'));
                }
                $paid = $locked->fee_cents->cents - $owed;
                if ($newFee < $paid) {
                    throw new DomainException(__('Ya se han cobrado :paid de esta cuota: la nueva no puede ser menor.', ['paid' => Money::fromCents($paid)->formatted()]));
                }
                $update += ['fee_cents' => $newFee, 'fee_override_by' => $overridden ? $actor->id : null];
                $outcome = ['fee_kept' => false, 'difference_cents' => 0];
            } else {
                $outcome = ['fee_kept' => true, 'difference_cents' => $default - $locked->fee_cents->cents];
            }

            $locked->update($update);
            (new RecordAuditLog)->handle('membership.tier.changed', $locked, $before, [
                'tier_id' => $tier->id, 'fee_cents' => $locked->fresh()?->fee_cents->cents, 'reason' => $reason,
                'fee_kept' => $outcome['fee_kept'], 'difference_cents' => $outcome['difference_cents'],
            ]);

            return $outcome;
        });
    }
}
