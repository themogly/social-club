<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
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
 *
 * Prompt 364 — the counter's *Actualizar peso del bote* (Existencias) is this action too, so it applies 359's forgotten
 * top-up rule first, through the SAME helper as the end-of-day count ({@see AbsorbUnrecordedTopUp}): a jar weighed heavy
 * while sealed stock exists takes the surplus from the reserve, and only what is beyond it is adjusted. Every recount is
 * audited (`stock.recounted`), a zero difference included, so the screen can say when a jar was last weighed.
 */
class RecountBatch
{
    /**
     * @param  int  $counted  centigrams, or units for a unit product
     * @return array{before: int, after: int, delta: int, absorbed: int}
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
            $absorbed = (new AbsorbUnrecordedTopUp)->handle($locked, $counted - $before, ['operator_id' => $actor->id]);
            $delta = $counted - $before - $absorbed;

            if ($delta !== 0) {
                (new RecordStockMovement)->handle($locked, StockMovementType::ADJUSTMENT, $delta, [
                    'reason' => trim($reason) !== '' ? trim($reason) : __('Recuento'),
                    'operator_id' => $actor->id,
                ]);
            }
            (new RecordAuditLog)->handle('stock.recounted', $locked, ['quantity' => $before], ['quantity' => $counted, 'absorbed' => $absorbed, 'reason' => trim($reason) ?: null]);

            return ['before' => $before, 'after' => $counted, 'delta' => $delta, 'absorbed' => $absorbed];
        });
    }
}
