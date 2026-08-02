<?php

namespace App\Support;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use Illuminate\Support\Carbon;

/**
 * Prompt 135 — turns a fired eligibility rule into what the operator can SAY and DO about it. It is pure
 * presentation: {@see ResolveMemberEligibility} and ResolveMemberLimits are untouched;
 * this reads what they already returned and names the rule in the member's own terms ("en carencia hasta el
 * 14/08", "cuota pendiente 25,00 €", "deuda de 12,50 € por encima del límite") with the fix beside it. Where the
 * answer is genuinely "nothing at the counter", it says so and says when or who — because an operator who knows
 * a member is in carencia until Thursday can tell them, and one who sees a generic refusal cannot.
 *
 * The unpaid-fee ACTION is already inline at the door and POS (prompt 127); this names every other rule and adds
 * the remedy note. It never changes a verdict or makes a rule easier to pass.
 *
 * @phpstan-type Remedy array{detail: string, remedy: ?string}
 */
class VerdictRemedy
{
    /**
     * @param  array{rule: string, satisfied: bool, mode: string, message: string}  $rule
     * @return Remedy
     */
    public static function describe(array $rule, Member $member, Location $location): array
    {
        return match ($rule['rule']) {
            'carencia' => [
                'detail' => $member->carencia_ends_at instanceof Carbon
                    ? __('En carencia hasta el :date (puede entrar, aún no dispensarse).', ['date' => $member->carencia_ends_at->format('d/m/Y')])
                    : $rule['message'],
                'remedy' => null,
            ],
            'unpaid_fee' => [
                'detail' => self::feeDetail($member, $location) ?? $rule['message'],
                'remedy' => null, // the collect-fee action is already inline (prompt 127)
            ],
            'debt' => [
                'detail' => self::debtDetail($member, $location) ?? $rule['message'],
                'remedy' => __('Debe saldar el monedero para poder continuar.'),
            ],
            'membership' => [
                'detail' => $rule['message'],
                'remedy' => __('Renueva su cuota desde su ficha para poder dispensarle.'),
            ],
            'sanction' => [
                'detail' => $rule['message'],
                'remedy' => __('No se resuelve en el mostrador: consulta con un responsable.'),
            ],
            'aforo' => [
                'detail' => self::aforoDetail($location),
                'remedy' => null,
            ],
            default => ['detail' => $rule['message'], 'remedy' => null],
        };
    }

    private static function feeDetail(Member $member, Location $location): ?string
    {
        $membership = self::activeMembership($member, $location);
        if ($membership === null) {
            return null;
        }

        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');
        $owed = max(0, $membership->fee_cents->cents - $paid);

        return $owed > 0 ? __('Cuota de socio pendiente: :amount.', ['amount' => Money::fromCents($owed)->formatted()]) : null;
    }

    private static function debtDetail(Member $member, Location $location): ?string
    {
        $balance = Wallet::balance($member->id, $location->id);

        return $balance < 0
            ? __('Deuda de :amount por encima del límite del monedero.', ['amount' => Money::fromCents(-$balance)->formatted()])
            : null;
    }

    private static function aforoDetail(Location $location): string
    {
        $capacity = Occupancy::capacity($location);

        return $capacity !== null
            ? __('Aforo completo (:current/:capacity).', ['current' => Occupancy::current($location), 'capacity' => $capacity])
            : __('Aforo completo.');
    }

    private static function activeMembership(Member $member, Location $location): ?Membership
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first();
    }
}
