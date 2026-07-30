<?php

namespace App\Models\Concerns;

use App\Models\Location;
use App\Models\Scopes\LocationScope;
use App\Support\ActiveScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-location model concern: applies the location global scope and auto-fills
 * `location_id` on create from the active location when not set explicitly.
 * Use ALONGSIDE BelongsToOrganisation (per-location rows also carry organisation_id).
 */
trait ScopedToLocation
{
    public static function bootScopedToLocation(): void
    {
        static::addGlobalScope(new LocationScope);

        static::creating(function ($model): void {
            if ($model->location_id === null) {
                $model->location_id = app(ActiveScope::class)->locationId();
            }
        });
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Explicitly query a single location regardless of the active scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLocation(Builder $query, string $locationId): Builder
    {
        return $query->withoutGlobalScope(LocationScope::class)
            ->where($this->getTable().'.location_id', $locationId);
    }
}
