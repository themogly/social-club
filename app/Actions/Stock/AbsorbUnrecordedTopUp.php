<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockMovementType;
use App\Models\Batch;

/**
 * Prompt 359's forgotten «Rellenar», in ONE place (prompt 364): a jar weighed HEAVIER than the system says while its batch
 * still holds sealed stock is a bag opened into the jar with nobody tapping Rellenar. The surplus, up to the reserve, is
 * moved reserve → jar (a `RESERVE_OUT` pair, reason «Rellenado sin registrar», audited `stock.topped_up_unrecorded`) —
 * no stock is created. What it returns is what was absorbed; the caller adjusts only what is left. A jar weighed LIGHTER
 * is a real shortfall: nothing is absorbed and the reserve is never touched.
 *
 * Called inside the caller's transaction by both writers that set a jar to what the scale says — the end-of-day count
 * ({@see CommitStockTake}) and *Actualizar peso del bote* / *Recuento* ({@see RecountBatch}) — so the two cannot drift.
 */
class AbsorbUnrecordedTopUp
{
    /**
     * @param  int  $variance  counted − expected, in centigrams (jar only)
     * @param  array{stock_take_id?: ?string, operator_id?: ?string}  $options
     * @return int the centigrams absorbed from the reserve (0 when none)
     */
    public function handle(Batch $batch, int $variance, array $options = []): int
    {
        $absorbed = $variance > 0 && ! $batch->isUnitType() ? min($variance, $batch->reserve_cg->centigrams) : 0;
        if ($absorbed <= 0) {
            return 0;
        }

        $movement = $options + ['reason' => 'Rellenado sin registrar'];
        $recorder = new RecordStockMovement;
        $recorder->handle($batch, StockMovementType::RESERVE_OUT, -$absorbed, $movement + ['reserve' => true]);
        $recorder->handle($batch, StockMovementType::RESERVE_OUT, $absorbed, $movement);
        (new RecordAuditLog)->handle('stock.topped_up_unrecorded', $batch, null, array_filter(['cg' => $absorbed, 'stock_take_id' => $options['stock_take_id'] ?? null]));

        return $absorbed;
    }
}
