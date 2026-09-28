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
 * - price overrides → the Consumption report's "Ajustes de precio" and the discounts report;
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
