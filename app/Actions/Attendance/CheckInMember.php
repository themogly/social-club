<?php

namespace App\Actions\Attendance;

use App\Actions\RecordAuditLog;
use App\Enums\CheckInMethod;
use App\Exceptions\CheckInBlockedException;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Support\CounterOperator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admit a member at the door: run the door eligibility checks, enforce the matrix,
 * and open a check-in scoped to the active location, attributed to the PIN-identified
 * operator. Prevents a double concurrent check-in (locks open sessions). A blocked
 * door may be overridden by a `checkin.override` holder — always logged.
 *
 * @phpstan-type CheckInOptions array{operator_id?: ?string, method?: CheckInMethod, override?: bool, override_by?: ?\App\Models\User, override_reason?: ?string}
 */
class CheckInMember
{
    /**
     * @param  CheckInOptions  $options
     */
    public function handle(Member $member, Location $location, array $options = []): CheckIn
    {
        return DB::transaction(function () use ($member, $location, $options): CheckIn {
            // Idempotent + concurrency-safe: one open check-in per member per location.
            $open = CheckIn::query()->withoutGlobalScopes()
                ->where('member_id', $member->id)->where('location_id', $location->id)
                ->whereNull('checked_out_at')->lockForUpdate()->first();

            if ($open !== null) {
                return $open;
            }

            $verdict = (new ResolveMemberEligibility)->handle($member, $location, 'door');

            if ($verdict->isBlocked()) {
                $by = $options['override_by'] ?? null;

                if (! ($options['override'] ?? false) || $by === null) {
                    throw new CheckInBlockedException(implode(' ', $verdict->blockingMessages()));
                }
                if (! $by->can('checkin.override')) {
                    throw new AuthorizationException('Overriding a door check requires the checkin.override permission.');
                }

                (new RecordAuditLog)->handle('checkin.override', $member, null, [
                    'location_id' => $location->id,
                    'rules' => array_column($verdict->blockingRules(), 'rule'),
                    'authorised_by' => $by->id,
                    'reason' => $options['override_reason'] ?? null,
                ]);
            }

            return CheckIn::create([
                'organisation_id' => $member->organisation_id,
                'member_id' => $member->id,
                'location_id' => $location->id,
                'checked_in_at' => now(),
                'operator_id' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
                'method' => $options['method'] ?? CheckInMethod::MANUAL,
            ]);
        });
    }
}
