<?php

namespace App\Models\Concerns;

use App\Models\Organisation;
use App\Models\Scopes\OrganisationScope;
use App\Support\ActiveScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies the organisation global scope and auto-fills `organisation_id` on create
 * from the active scope when not set explicitly.
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new OrganisationScope);

        static::creating(function ($model): void {
            if ($model->organisation_id === null) {
                $model->organisation_id = app(ActiveScope::class)->organisationId();
            }
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
