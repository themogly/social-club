<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prompt 359 — «Rellenar»: a sealed top-up bag of this batch opened into the jar. Moves `$cg` (null = «Toda la reserva»)
 * from the batch's reserve to its jar: two movements through the one stock writer (reserve −, jar +), RESERVE_OUT, in one
 * transaction, never more than the reserve. A move within the sede: stock and register totals do not change. Anyone who
 * can dispense (staff do this all day), as the PIN operator.
 */
class TopUpFromReserve
{
    public function handle(Batch $batch, ?int $cg, User $actor): Batch
    {
        if (! $actor->can('pos.use')) {
            throw new AuthorizationException(__('No tienes permiso para mover existencias.'));
        }

        return DB::transaction(function () use ($batch, $cg, $actor): Batch {
            $locked = Batch::query()->withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $reserve = $locked->reserve_cg->centigrams;
            $amount = $cg ?? $reserve;
            if ($amount <= 0 || $amount > $reserve) {
                throw new RuntimeException(__('Indica una cantidad entre 0 y la reserva (:grams).', ['grams' => $locked->reserve_cg->formatted()]));
            }

            $writer = new RecordStockMovement;
            $options = ['operator_id' => $actor->id, 'reason' => 'Relleno desde reserva'];
            $writer->handle($locked, StockMovementType::RESERVE_OUT, -$amount, $options + ['reserve' => true]);
            $writer->handle($locked, StockMovementType::RESERVE_OUT, $amount, $options);

            (new RecordAuditLog)->handle('stock.topped_up', $locked, null, ['cg' => $amount, 'location_id' => $locked->location_id]);

            return $locked->fresh() ?? $locked;
        });
    }
}
