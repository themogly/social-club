<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * *Recuento* (prompt 305) — set a part to what the scale says. The owner types the COUNTED quantity, never the
 * arithmetic: the difference is computed here, inside the transaction, against the part's remaining quantity RE-READ
 * UNDER A LOCK — so a sale made between opening the form and saving is not counted twice — and written as ONE ADJUSTMENT
 * through the single stock writer, exactly as *Ajuste* does. No difference, no movement. Gated on `stock.take` (the
 * permission of the till's blind recount).
 */
class RecountBatch
{
    /**
     * @param  int  $counted  centigrams, or units for a unit product
     * @return array{before: int, after: int, delta: int}
     *
     * @throws AuthorizationException
     */
    public function handle(Batch $batch, int $counted, string $reason, User $actor): array
    {
        if (! $actor->can('stock.take')) {
            throw new AuthorizationException(__('No tienes permiso para hacer recuentos.'));
        }
        if ($counted < 0) {
            throw new InvalidArgumentException(__('La cantidad contada no puede ser negativa.'));
        }

        return DB::transaction(function () use ($batch, $counted, $reason, $actor): array {
            /** @var Batch $locked */
            $locked = Batch::query()->withoutGlobalScopes()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->isUnitType() ? (int) ($locked->remaining_units ?? 0) : $locked->remaining_cg->centigrams;
            $delta = $counted - $before;

            if ($delta !== 0) {
                (new RecordStockMovement)->handle($locked, StockMovementType::ADJUSTMENT, $delta, [
                    'reason' => trim($reason) !== '' ? trim($reason) : __('Recuento'),
                    'operator_id' => $actor->id,
                ]);
            }

            return ['before' => $before, 'after' => $counted, 'delta' => $delta];
        });
    }
}
