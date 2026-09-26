<?php

namespace App\Actions\Stock;

use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Support\Weight;
use RuntimeException;

/**
 * Prompt 250 — the dispensary stops asking which lote. Everything of one genetic goes into one jar; the
 * operator weighs and adds, and the SYSTEM decides which batches the grams came from — oldest first (FEFO),
 * splitting into the next batch when the old one runs out.
 *
 * This is the ALLOCATOR, not a writer: it reads the sede's dispensable batches of a genetic in FEFO order
 * (`acquired_or_harvested_on`, then `id` — {@see SelectBatch::fefo()}'s ordering, over {@see Batch::scopeDispensable}),
 * and returns which batches to draw a requested quantity from, and how much from each. {@see RecordStockMovement}
 * — still the single stock writer — is then called once per returned part. The rows are LOCKED for update while
 * this runs, so two tablets cannot both allocate the last gram (the same guard `RecordStockMovement` takes when
 * it decrements). When the batches together cannot cover the quantity it throws the same "Stock insuficiente"
 * `RuntimeException` the writer throws, but named for the GENETIC and the sede's total — never a lote, because
 * in automatic mode the operator never chose one.
 *
 * The quantity is in the genetic's own unit: centigrams for a WEIGHT genetic, whole units for a UNIT genetic
 * (a unit is never split). It does not price, does not check limits and does not change a quantity — the caller
 * has already priced and limit-checked the whole line; this only maps it onto batches.
 */
class AllocateFromBatches
{
    /**
     * @return list<array{batch: Batch, qty: int}> FEFO parts summing to exactly $quantity
     *
     * @throws RuntimeException when the sede's dispensable batches of this genetic cannot cover $quantity
     */
    public function handle(Genetic $genetic, Location $location, int $quantity): array
    {
        $isUnit = $genetic->isUnitType();

        $batches = Batch::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $location->id)
            ->dispensable()
            ->orderBy('acquired_or_harvested_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $available = 0;
        $plan = [];

        foreach ($batches as $batch) {
            $inBatch = $isUnit
                ? (int) ($batch->remaining_units ?? 0)
                : $batch->remaining_cg->centigrams;

            $available += $inBatch;

            if ($remaining <= 0 || $inBatch <= 0) {
                continue;
            }

            $take = min($inBatch, $remaining);
            $plan[] = ['batch' => $batch, 'qty' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new RuntimeException(__('Stock insuficiente de :genetic en :sede (disponible: :available).', [
                'genetic' => $genetic->name,
                'sede' => $location->name,
                'available' => $isUnit
                    ? trans_choice(':count unidad|:count unidades', $available, ['count' => $available])
                    : Weight::fromCentigrams($available)->formatted(),
            ]));
        }

        return $plan;
    }
}
