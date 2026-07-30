<?php

namespace App\Models\Scopes;

use App\Support\ActiveScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains a query to the current organisation. Single-org today; the hook for
 * multi-org SaaS tomorrow. Bypass deliberately with `withoutGlobalScope`.
 *
 * @implements Scope<Model>
 */
class OrganisationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organisationId = app(ActiveScope::class)->organisationId();

        if ($organisationId !== null) {
            $builder->where($model->getTable().'.organisation_id', $organisationId);
        }
    }
}
