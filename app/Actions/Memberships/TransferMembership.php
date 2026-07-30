<?php

namespace App\Actions\Memberships;

use App\Actions\RecordAuditLog;
use App\Models\Location;
use App\Models\Membership;

/** Move a membership to another location (permissioned by the caller, audited; both locations' reports reflect it). */
class TransferMembership
{
    public function handle(Membership $membership, Location $toLocation): Membership
    {
        $fromLocationId = $membership->location_id;

        $membership->update(['location_id' => $toLocation->id]);

        (new RecordAuditLog)->handle('membership.transferred', $membership, [
            'location_id' => $fromLocationId,
        ], [
            'location_id' => $toLocation->id,
        ]);

        return $membership;
    }
}
