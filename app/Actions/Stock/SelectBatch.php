<?php

namespace App\Actions\Stock;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;

/** Batch selection at the counter — FEFO (oldest open, non-expired, in-stock batch). */
class SelectBatch
{
    /** The default batch to dispense from: oldest open, non-expired, with stock. */
    public function fefo(Genetic $genetic, Location $location): ?Batch
    {
        return Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $location->id)
            ->where('status', BatchStatus::OPEN->value)
            ->where('remaining_cg', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))
            ->orderBy('acquired_or_harvested_on')
            ->orderBy('id')
            ->first();
    }

    /** May this batch be dispensed from right now? (Open, in stock, not expired.) */
    public function isDispensable(Batch $batch): bool
    {
        return $batch->status === BatchStatus::OPEN
            && $batch->remaining_cg->centigrams > 0
            && ($batch->expires_on === null || $batch->expires_on->greaterThanOrEqualTo(today()));
    }
}
