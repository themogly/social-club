<?php

namespace App\Models;

use App\Enums\DataRequestType;
use Database\Factories\DataRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidences that a data-subject request (RGPD) was answered in time. */
class DataRequest extends Model
{
    /** @use HasFactory<DataRequestFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'member_id', 'type', 'requested_at', 'completed_at', 'handled_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
