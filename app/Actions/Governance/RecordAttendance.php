<?php

namespace App\Actions\Governance;

use App\Enums\AttendanceMode;
use App\Models\AssemblyAttendance;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaRecipient;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Record (or correct) one member's attendance at an assembly — present or by proxy. Only against an ISSUED
 * convocatoria and only for a member on its FROZEN roll: attendance for someone never convened would corrupt
 * the quorum. The name is snapshotted from the roll so it survives a later member scrub. A member attends
 * once — re-recording updates the same row (a mode/proxy correction), never a duplicate.
 */
class RecordAttendance
{
    public function handle(Convocatoria $convocatoria, Member $member, AttendanceMode $mode, ?string $proxyHolder, User $actor): AssemblyAttendance
    {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Recording assembly attendance requires the minutes.manage permission.');
        }

        if (! $convocatoria->isIssued()) {
            throw new RuntimeException('Attendance can only be recorded for an issued convocatoria.');
        }

        $recipient = ConvocatoriaRecipient::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('member_id', $member->id)
            ->first();

        if ($recipient === null) {
            throw new RuntimeException('This member is not on the convocatoria roll and cannot be marked present.');
        }

        if ($mode === AttendanceMode::PROXY && trim((string) $proxyHolder) === '') {
            throw new RuntimeException('A proxy attendance must name who holds the proxy.');
        }

        return AssemblyAttendance::updateOrCreate(
            ['convocatoria_id' => $convocatoria->id, 'member_id' => $member->id],
            [
                'organisation_id' => $convocatoria->organisation_id,
                'name' => $recipient->name,
                'mode' => $mode,
                'proxy_holder' => $mode === AttendanceMode::PROXY ? trim((string) $proxyHolder) : null,
                'recorded_by' => $actor->id,
            ],
        );
    }

    /** Remove a mistaken attendance mark. */
    public function remove(Convocatoria $convocatoria, Member $member, User $actor): void
    {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Recording assembly attendance requires the minutes.manage permission.');
        }

        AssemblyAttendance::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('member_id', $member->id)
            ->delete();
    }
}
