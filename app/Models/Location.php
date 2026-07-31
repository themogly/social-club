<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A premises — the operational scope. Its timezone + business_day_cutoff drive
 * every daily aggregate and daily limit via BusinessDay.
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'name', 'address', 'capacity', 'timezone',
        'business_day_cutoff', 'opening_time', 'closing_time', 'accent', 'active',
        'terminals',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
            'terminals' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * The configured till terminals for this location (prompt 84) — the picker's options.
     *
     * @return list<string>
     */
    public function terminalNames(): array
    {
        return array_values(array_filter((array) ($this->terminals ?? []), 'is_string'));
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
