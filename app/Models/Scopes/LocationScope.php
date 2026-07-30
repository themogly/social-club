<?php

namespace App\Models\Scopes;

use App\Support\ActiveScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains a per-location query to the active location. When "All locations" is
 * active (owner rollup) the scope adds nothing and the query crosses locations.
 * Cross-location access is therefore deliberate — switch to a location, or use
 * `withoutGlobalScope(LocationScope::class)` explicitly.
 *
 * @implements Scope<Model>
 */
class LocationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $locationId = app(ActiveScope::class)->locationId();

        if ($locationId !== null) {
            $builder->where($model->getTable().'.location_id', $locationId);
        }
    }
}
