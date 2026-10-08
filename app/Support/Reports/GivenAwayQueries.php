<?php

namespace App\Support\Reports;

use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 291 — the ONE query behind each "given away" figure that already existed, so the Descuentos y ajustes report
 * and the report that showed it first can never disagree (false-green §18, two computations of one figure):
 *
 * - price overrides → the Consumption report's "Ajustes de precio: cedido / recuperado" and the discounts report (370);
 * - waived fees → the Financial report's "Cuotas condonadas" and the discounts report.
 *
 * Only COMPLETED dispensations count: a voided sale gave nothing away (its override used to count in Consumption).
 */
class GivenAwayQueries
{
    /**
     * Completed dispensations whose price was overridden, in the window, at these sedes.
     *
     * @param  list<string>  $locationIds
     */
    public static function priceOverrides(array $locationIds, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return DB::table('dispensations')
            ->whereIn('dispensations.location_id', $locationIds)
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereNotNull('dispensations.original_total_cents')
            ->where('dispensations.dispensed_at', '>=', $start)->where('dispensations.dispensed_at', '<', $end);
    }

    /**
     * Prompt 370 — what the price overrides gave away and what they recovered, as two figures: since 356 an adjustment can
     * RAISE the price, and one sum hid the raises inside the give-aways. Both reports read this.
     *
     * **No SQL arithmetic on an unsigned column.** `original_total_cents` is UNSIGNED, and MySQL evaluates `unsigned − signed`
     * as unsigned, so `SUM(original_total_cents - total_cents)` over a raised sale is error 1690 (the register crashed in
     * production; SQLite has no unsigned arithmetic, so the suite stayed green). Here the columns are SUMMED separately — for
     * the lowered rows and for the raised ones — and subtracted in PHP. `UnsignedArithmeticTest` guards the pattern.
     *
     * @param  list<string>  $locationIds
     * @return array{given: int, recovered: int}
     */
    public static function priceOverrideTotals(array $locationIds, CarbonInterface $start, CarbonInterface $end): array
    {
        $sums = fn (string $operator): object => self::priceOverrides($locationIds, $start, $end)
            ->whereColumn('dispensations.total_cents', $operator, 'dispensations.original_total_cents')
            ->selectRaw('COALESCE(SUM(dispensations.original_total_cents), 0) as original, COALESCE(SUM(dispensations.total_cents), 0) as charged')
            ->first() ?? (object) ['original' => 0, 'charged' => 0];

        $lowered = $sums('<');
        $raised = $sums('>');

        return [
            'given' => (int) $lowered->original - (int) $lowered->charged,
            'recovered' => (int) $raised->charged - (int) $raised->original,
        ];
    }

    /**
     * Waived membership fees in the window, at these sedes (the membership's sede), joined to the member and the
     * person who recorded the waiver.
     *
     * @param  list<string>  $locationIds
     */
    public static function waivedFees(array $locationIds, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return DB::table('membership_fee_payments')
            ->join('memberships', 'membership_fee_payments.membership_id', '=', 'memberships.id')
            ->join('members', 'memberships.member_id', '=', 'members.id')
            ->leftJoin('users', 'membership_fee_payments.recorded_by', '=', 'users.id')
            ->whereIn('memberships.location_id', $locationIds)
            ->where('membership_fee_payments.method', FeePaymentMethod::WAIVED->value)
            ->where('membership_fee_payments.paid_at', '>=', $start)->where('membership_fee_payments.paid_at', '<', $end);
    }
}
