<?php

namespace App\Models;

use App\Enums\AttendanceMode;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\AssemblyAttendanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's attendance at an assembly (the convocatoria), in person or by proxy. Recorded against the
 * FROZEN roll — a member attends once (unique per convocatoria). The name is snapshotted so the acta and the
 * anonymisation redaction survive a later member scrub, exactly as convocatoria_recipients does. Written only
 * through App\Actions\Governance\RecordAttendance.
 */
class AssemblyAttendance extends Model
{
    /** @use HasFactory<AssemblyAttendanceFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'convocatoria_id', 'member_id', 'name', 'mode', 'proxy_holder', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'mode' => AttendanceMode::class,
        ];
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
