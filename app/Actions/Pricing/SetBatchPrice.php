<?php

namespace App\Actions\Pricing;

use App\Actions\RecordAuditLog;
use App\Models\Batch;
use App\Models\User;
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

        (new RecordAuditLog)->handle('batch.price.updated', $batch, $before, $batch->only(['price_per_gram_cents', 'price_per_unit_cents', 'price_per_eighth_cents']));

        return $batch;
    }
}
