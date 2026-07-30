<?php

namespace App\Models;

use App\Enums\CheckInMethod;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'member_id', 'location_id', 'checked_in_at',
        'checked_out_at', 'auto_checked_out', 'operator_id', 'method',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'auto_checked_out' => 'boolean',
            'method' => CheckInMethod::class,
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * Members currently inside (checked in, not yet checked out).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInsideNow(Builder $query): Builder
    {
        return $query->whereNull('checked_out_at');
    }
}
