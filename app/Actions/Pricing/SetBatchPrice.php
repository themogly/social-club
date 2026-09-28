<?php

namespace App\Actions\Pricing;

use App\Actions\RecordAuditLog;
use App\Models\Batch;
use App\Models\User;
use App\Support\BelowCost;
use App\Support\LocationSwitcher;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Change a batch's sale price (prompt 278, Ben's 271) — the one writer after intake. Gated on `prices.manage`, audited
 * from → to (`batch.price.updated`), and it only affects sales made after the change: every dispensation line froze the
 * rate it was charged at (CommitDispensation).
 */
class SetBatchPrice
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Batch $batch, int $rateCents, ?int $eighthCents, User $actor): Batch
    {
        if (! $actor->can('prices.manage')) {
            throw new AuthorizationException(__('No tienes permiso para cambiar precios.'));
        }
        // Post-296 audit — and only at a location the actor works at (the owner works at all of them).
        if (! app(LocationSwitcher::class)->canAccess($actor, $batch->location_id)) {
            throw new AuthorizationException(__('Ese lote es de una sede en la que no trabajas.'));
        }
        if ($rateCents < 0 || ($eighthCents !== null && $eighthCents < 0)) {
            throw new InvalidArgumentException(__('El precio no puede ser negativo.'));
        }

        $isUnit = $batch->isUnitType();
        $before = $batch->only(['price_per_gram_cents', 'price_per_unit_cents', 'price_per_eighth_cents']);

        $batch->forceFill([
            'price_per_gram_cents' => $isUnit ? null : $rateCents,
            'price_per_unit_cents' => $isUnit ? $rateCents : null,
            'price_per_eighth_cents' => $isUnit ? null : $eighthCents,
        ])->save();

        $after = $batch->only(['price_per_gram_cents', 'price_per_unit_cents', 'price_per_eighth_cents']);
        if (BelowCost::forBatch($batch) !== []) {
            $after['below_cost'] = true; // prompt 295 — set below the batch's cost, knowingly (a warning, never a block)
        }

        (new RecordAuditLog)->handle('batch.price.updated', $batch, $before, $after);

        return $batch;
    }
}
