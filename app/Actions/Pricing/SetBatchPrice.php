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
 *
 * Prompt 382 — and its Local / Personal prices: `prices()` writes any of the nine price columns (null = blank, which is the
 * standard less the list's default %). The *Precios de la sede* screen calls it per changed batch with its own audit action
 * (`batch.prices_updated`); nothing is written, or audited, when nothing changed.
 */
class SetBatchPrice
{
    /**
     * The standard price (per gram or per unit, and the 3.5 g one), with the Local / Personal ones when given.
     *
     * @param  array<string, ?int>  $listPrices  any of the six local_* / staff_* columns
     *
     * @throws AuthorizationException
     */
    public function handle(Batch $batch, int $rateCents, ?int $eighthCents, User $actor, array $listPrices = []): Batch
    {
        $isUnit = $batch->isUnitType();
        $this->prices($batch, [
            'price_per_gram_cents' => $isUnit ? null : $rateCents,
            'price_per_unit_cents' => $isUnit ? $rateCents : null,
            'price_per_eighth_cents' => $isUnit ? null : $eighthCents,
            ...$listPrices,
        ], $actor);

        return $batch;
    }

    /**
     * Write these price columns. Returns whether anything changed (an unchanged batch writes and audits nothing).
     *
     * @param  array<string, ?int>  $prices  a subset of {@see Batch::PRICE_COLUMNS}
     *
     * @throws AuthorizationException
     */
    public function prices(Batch $batch, array $prices, User $actor, string $auditAction = 'batch.price.updated'): bool
    {
        if (! $actor->can('prices.manage')) {
            throw new AuthorizationException(__('No tienes permiso para cambiar precios.'));
        }
        // Post-296 audit — and only at a location the actor works at (the owner works at all of them).
        if (! app(LocationSwitcher::class)->canAccess($actor, $batch->location_id)) {
            throw new AuthorizationException(__('Ese lote es de una sede en la que no trabajas.'));
        }
        $prices = array_intersect_key($prices, array_flip(Batch::PRICE_COLUMNS));
        if (array_filter($prices, fn (?int $cents): bool => $cents !== null && $cents < 0) !== []) {
            throw new InvalidArgumentException(__('El precio no puede ser negativo.'));
        }
        $standard = $batch->isUnitType() ? 'price_per_unit_cents' : 'price_per_gram_cents';
        if (array_key_exists($standard, $prices) && $prices[$standard] === null) {
            throw new InvalidArgumentException(__('El precio Estándar no puede quedar vacío.'));
        }

        $before = $batch->storedPrices();
        if (! $batch->forceFill($prices)->isDirty()) {
            return false;
        }
        $batch->save();

        // Only the prices that changed, before and after.
        $changed = array_intersect_key($batch->getChanges(), $prices);
        $before = array_intersect_key($before, $changed);
        $after = $changed;
        if (BelowCost::forBatchLists($batch) !== []) {
            $after['below_cost'] = true; // prompt 295 — set below the batch's cost, knowingly (a warning, never a block)
        }

        (new RecordAuditLog)->handle($auditAction, $batch, $before, $after);

        return true;
    }
}
