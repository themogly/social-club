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
 * merma, transfer). Locks the batch/article row, applies the SIGNED delta, refuses to
 * go negative, and appends one StockMovement — all in a transaction. The POS
 * (CommitDispensation) and every UI action call this; nothing else touches the
 * remaining_cg / remaining_units / stock columns.
 *
 * $delta is SIGNED (caller decides direction): +1250 intake, −350 dispense, ±X adjust.
 *
 * QUANTITY RULE (corrected in prompt 21 — do NOT copy the old "batch = cg, article =
 * units"): the quantity column keys off the stockable's UNIT TYPE, not whether it is a
 * Batch or an Article. A WEIGHT-type Batch decrements remaining_cg and writes qty_cg; a
 * UNIT-type Batch (preroll/edible) decrements remaining_units and writes qty_units; an
 * Article is always units (qty_units). So a UNIT Batch and an Article share the units
 * path; a WEIGHT Batch is the only cg path.
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
            /** @var Batch|Article $locked */
            $locked = $stockable->newQueryWithoutScopes()->whereKey($stockable->getKey())->lockForUpdate()->firstOrFail();

            $unitBased = $locked instanceof Article || $locked->isUnitType();

            if (! $unitBased) {
                /** @var Batch $locked */
                $new = $locked->remaining_cg->centigrams + $delta;
                if ($new < 0) {
                    throw new RuntimeException(__('Stock insuficiente en el lote :batch.', ['batch' => $locked->batch_no]));
                }
                $locked->remaining_cg = Weight::fromCentigrams($new);
            } elseif ($locked instanceof Batch) {
                $new = (int) ($locked->remaining_units ?? 0) + $delta;
                if ($new < 0) {
                    throw new RuntimeException(__('Stock insuficiente en el lote :batch.', ['batch' => $locked->batch_no]));
                }
                $locked->remaining_units = $new;
            } else {
                $new = $locked->stock + $delta;
                if ($new < 0) {
                    throw new RuntimeException(__('Stock insuficiente para el artículo :name.', ['name' => $locked->name]));
                }
                $locked->stock = $new;
            }
            $locked->save();

            return StockMovement::create([
                'organisation_id' => $locked->organisation_id,
                'location_id' => $locked->location_id,
                'stockable_type' => $locked->getMorphClass(),
                'stockable_id' => $locked->getKey(),
                'qty_cg' => $unitBased ? null : $delta,
                'qty_units' => $unitBased ? $delta : null,
                'type' => $type,
                'reason' => $options['reason'] ?? null,
                'operator_id' => $options['operator_id'] ?? Auth::id(),
                'reference' => $options['reference'] ?? null,
                'stock_take_id' => $options['stock_take_id'] ?? null,
            ]);
        });
    }
}
