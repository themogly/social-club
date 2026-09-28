<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tablet registered as a counter (prompt 289). It decides only what a signed-out tablet opens — the counter's lock
 * surface at its home sede — and authorises nothing: every counter action is still the PIN operator's.
 */
class CounterTerminal extends Model
{
    use BelongsToOrganisation, HasUlids;

    protected $fillable = [
        'organisation_id', 'location_id', 'name', 'token_hash', 'registered_by', 'registered_at',
        'last_seen_at', 'revoked_at', 'revoked_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'registered_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }

    /**
     * @param  Builder<CounterTerminal>  $query
     * @return Builder<CounterTerminal>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
