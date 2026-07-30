<?php

namespace App\Support;

use App\Enums\MemberStatus;
use App\Models\Member;

/**
 * The sum of all ACTIVE members' declared monthly forecasts (*previsión de consumo*)
 * is the club's authorised cultivation/acquisition volume. Exposed for prompts 14/15.
 */
class ConsumptionForecast
{
    /** Authorised monthly volume in centigrams (self-declared aggregate). */
    public static function authorisedVolumeCg(string $organisationId): int
    {
        return (int) Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('status', MemberStatus::ACTIVE->value)
            ->sum('declared_monthly_cg');
    }
}
