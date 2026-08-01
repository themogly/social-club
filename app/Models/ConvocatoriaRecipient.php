<?php

namespace App\Models;

use App\Enums\ConvocatoriaRecipientStatus;
use Database\Factories\ConvocatoriaRecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member on a convocatoria's FROZEN roll. The number/name/email are snapshotted at issue and never
 * recomputed, so the roll evidences exactly who was convened even as the membership later changes. A
 * NO_EMAIL row is a member the club could not reach by email — recorded, so it is visible and actionable,
 * never silently missing.
 */
class ConvocatoriaRecipient extends Model
{
    /** @use HasFactory<ConvocatoriaRecipientFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'convocatoria_id', 'member_id', 'member_no', 'name', 'email', 'status', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConvocatoriaRecipientStatus::class,
            'notified_at' => 'datetime',
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
}
