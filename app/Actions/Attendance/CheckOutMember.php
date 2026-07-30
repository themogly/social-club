<?php

namespace App\Actions\Attendance;

use App\Models\CheckIn;

/** Close an open check-in. A check-out can never precede its check-in. */
class CheckOutMember
{
    public function handle(CheckIn $checkIn): CheckIn
    {
        if ($checkIn->checked_out_at === null) {
            $out = now();
            // Never before the check-in.
            if ($out->lessThan($checkIn->checked_in_at)) {
                $out = $checkIn->checked_in_at;
            }
            $checkIn->update(['checked_out_at' => $out, 'auto_checked_out' => false]);
        }

        return $checkIn;
    }
}
