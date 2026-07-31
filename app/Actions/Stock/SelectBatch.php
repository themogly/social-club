<?php

namespace App\Actions\Stock;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;

/**
 * Batch selection at the counter — FEFO (oldest open, non-expired, in-stock batch).
 * "In stock" keys off the genetic's unit_type: remaining_cg for WEIGHT, remaining_units
 * for UNIT (preroll/edible).
 */
class SelectBatch
{
    /** The default batch to dispense from: oldest open, non-expired, with stock (FEFO). */
    public function fefo(Genetic $genetic, Location $location): ?Batch
    {
        return Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $location->id)
            ->dispensable()
            ->orderBy('acquired_or_harvested_on')
            ->orderBy('id')
            ->first();
    }

    /** May this batch be dispensed from right now? (Open, in stock, not expired.) */
    public function isDispensable(Batch $batch): bool
    {
        $hasStock = $batch->isUnitType()
            ? (int) ($batch->remaining_units ?? 0) > 0
            : $batch->remaining_cg->centigrams > 0;

        return $batch->status === BatchStatus::OPEN
            && $hasStock
            && ($batch->expires_on === null || $batch->expires_on->greaterThanOrEqualTo(today()));
    }
}
