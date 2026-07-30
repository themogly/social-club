<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\Article;
use App\Models\Batch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE single writer for every stock change (intake, dispense, sale, adjustment,
 * merma, transfer). Locks the batch/article row, applies the SIGNED delta,
 * refuses to go negative, and appends one StockMovement — all in a transaction.
 * The POS (CommitDispensation) and every UI action call this; nothing else touches
 * the remaining_cg / stock columns.
 *
 * $delta is SIGNED (caller decides direction): +1250 cg intake, −350 cg dispense,
 * ±X adjustment. Batches carry `qty_cg`; articles carry `qty_units`.
 *
 * @phpstan-type MovementOptions array{reason?: ?string, operator_id?: ?string, reference?: ?string, stock_take_id?: ?string, actor?: ?User}
 */
class RecordStockMovement
{
    /**
     * @param  MovementOptions  $options
     */
    public function handle(Batch|Article $stockable, StockMovementType $type, int $delta, array $options = []): StockMovement
    {
        if ($type === StockMovementType::MERMA && ! (($options['actor'] ?? null)?->can('stock.merma') ?? false)) {
            throw new AuthorizationException('Recording merma requires the stock.merma permission.');
        }

        return DB::transaction(function () use ($stockable, $type, $delta, $options): StockMovement {
            $isBatch = $stockable instanceof Batch;

            /** @var Batch|Article $locked */
            $locked = $stockable->newQueryWithoutScopes()->whereKey($stockable->getKey())->lockForUpdate()->firstOrFail();

            if ($isBatch) {
                /** @var Batch $locked */
                $new = $locked->remaining_cg->centigrams + $delta;
                if ($new < 0) {
                    throw new RuntimeException("Insufficient stock in batch {$locked->batch_no}.");
                }
                $locked->remaining_cg = Weight::fromCentigrams($new);
            } else {
                /** @var Article $locked */
                $new = $locked->stock + $delta;
                if ($new < 0) {
                    throw new RuntimeException("Insufficient stock for article {$locked->name}.");
                }
                $locked->stock = $new;
            }
            $locked->save();

            return StockMovement::create([
                'organisation_id' => $locked->organisation_id,
                'location_id' => $locked->location_id,
                'stockable_type' => $locked->getMorphClass(),
                'stockable_id' => $locked->getKey(),
                'qty_cg' => $isBatch ? $delta : null,
                'qty_units' => $isBatch ? null : $delta,
                'type' => $type,
                'reason' => $options['reason'] ?? null,
                'operator_id' => $options['operator_id'] ?? Auth::id(),
                'reference' => $options['reference'] ?? null,
                'stock_take_id' => $options['stock_take_id'] ?? null,
            ]);
        });
    }
}
