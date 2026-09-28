<?php

namespace App\Support;

use App\Models\Batch;
use App\Models\GeneticPrice;
use Illuminate\Support\Facades\Log;

/**
 * Give every existing batch the sale price it would have been charged at before prompt 278 (Ben's 271): its genetic's
 * BASE price at the batch's own sede (`genetic_prices`, no tier). A batch whose genetic has no base price there is
 * LISTED, never guessed — it must be priced before its own price exists (until then the counter falls back to the
 * strain's sede price, which for these is none, so it cannot be dispensed). Idempotent: a batch that already carries a
 * price is left alone.
 */
class BatchPriceBackfill
{
    /**
     * @return list<array{batch_id: string, batch_no: string, genetic_id: string, location_id: string}> the batches left unpriced
     */
    public static function run(): array
    {
        $unpriced = [];

        Batch::query()->withoutGlobalScopes()
            ->whereNull('price_per_gram_cents')->whereNull('price_per_unit_cents')
            ->orderBy('id')
            ->chunkById(200, function ($batches) use (&$unpriced): void {
                foreach ($batches as $batch) {
                    /** @var Batch $batch */
                    $base = GeneticPrice::query()->withoutGlobalScopes()
                        ->where('genetic_id', $batch->genetic_id)->where('location_id', $batch->location_id)
                        ->whereNull('tier_id')
                        ->orderByDesc('active')->orderByDesc('updated_at')->orderByDesc('id')
                        ->first();

                    if ($base === null || ($base->price_per_gram_cents === null && $base->price_per_unit_cents === null)) {
                        $unpriced[] = ['batch_id' => $batch->id, 'batch_no' => (string) $batch->batch_no, 'genetic_id' => (string) $batch->genetic_id, 'location_id' => (string) $batch->location_id];

                        continue;
                    }

                    Batch::query()->withoutGlobalScopes()->whereKey($batch->id)->update([
                        'price_per_gram_cents' => $base->price_per_gram_cents,
                        'price_per_unit_cents' => $base->price_per_unit_cents,
                        'price_per_eighth_cents' => $base->price_per_eighth_cents,
                    ]);
                }
            });

        if ($unpriced !== []) {
            Log::warning('batch-price-backfill: batches left unpriced (no base price at their sede)', ['batches' => $unpriced]);
        }

        return $unpriced;
    }
}
