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
 * Prompt 359 — «Pasar a reserva»: staff weighed up sealed top-ups from what was in the jar. Moves `$cg` from the jar to the
 * batch's reserve — the reverse of {@see TopUpFromReserve}: two movements (jar −, reserve +), RESERVE_IN, one transaction,
 * never more than the jar. Only the total is asked for, never a bag count.
 */
class MoveToReserve
{
    public function handle(Batch $batch, int $cg, User $actor): Batch
    {
        if (! $actor->can('pos.use')) {
            throw new AuthorizationException(__('No tienes permiso para mover existencias.'));
        }

        return DB::transaction(function () use ($batch, $cg, $actor): Batch {
            $locked = Batch::query()->withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->isUnitType()) {
                throw new RuntimeException('Only a weight batch has a sealed reserve.');
            }
            $jar = $locked->remaining_cg->centigrams;
            if ($cg <= 0 || $cg > $jar) {
                throw new RuntimeException(__('Indica una cantidad entre 0 y lo que hay en el bote (:grams).', ['grams' => $locked->remaining_cg->formatted()]));
            }

            $writer = new RecordStockMovement;
            $options = ['operator_id' => $actor->id, 'reason' => 'A reserva'];
            $writer->handle($locked, StockMovementType::RESERVE_IN, -$cg, $options);
            $writer->handle($locked, StockMovementType::RESERVE_IN, $cg, $options + ['reserve' => true]);

            (new RecordAuditLog)->handle('stock.reserved', $locked, null, ['cg' => $cg, 'location_id' => $locked->location_id]);

            return $locked->fresh() ?? $locked;
        });
    }
}
