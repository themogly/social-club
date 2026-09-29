<?php

namespace App\Observers;

use App\Enums\ProductType;
use App\Enums\UnitType;
use App\Models\Genetic;
use DomainException;
use InvalidArgumentException;

/**
 * Keeps a Genetic's derived shape honest. `unit_type` is NEVER user-entered: it is
 * derived from `product_type` here and stored (mirrors the qty_cg/qty_units
 * precedent). Fields that only apply to one shape are normalised to null when they
 * do not apply, and a UNIT product without its per-unit gram content is refused —
 * the model-layer backstop for the Filament required() rule.
 */
class GeneticObserver
{
    public function saving(Genetic $genetic): void
    {
        // product_type defaults to FLOWER; unit_type is always derived, never trusted from input.
        $productType = $genetic->product_type ?? ProductType::FLOWER;
        $genetic->product_type = $productType;
        $genetic->unit_type = $productType->unitType();

        // Normalise fields that do not apply to this shape, so a type change never leaves stale data.
        if ($genetic->unit_type !== UnitType::UNIT) {
            $genetic->grams_per_unit_cg = null;
        }
        if ($productType !== ProductType::EDIBLE) {
            $genetic->thc_mg_per_unit = null;
        }
        if ($productType !== ProductType::CONCENTRATE) {
            $genetic->concentrate_subtype = null;
        }

        // A per-unit product must declare the gram content of one unit — grams_cg for
        // every UNIT dispensation line is computed from it.
        if ($genetic->unit_type === UnitType::UNIT
            && ($genetic->grams_per_unit_cg === null || (int) $genetic->grams_per_unit_cg <= 0)) {
            throw new InvalidArgumentException(
                'A per-unit product ('.$productType->value.') requires a positive grams_per_unit_cg.'
            );
        }
    }

    /**
     * Prompt 308 — a strain with stock left anywhere (the store included) is never deleted: its batches would carry on
     * under a hidden strain, read "(eliminada)" on the batch list, and a new strain would take its name. THE one guard —
     * the page's delete, the list's bulk delete and any forged request all end in `delete()`, which fires this.
     */
    public function deleting(Genetic $genetic): void
    {
        $left = $genetic->stockLeft();
        if ($left !== []) {
            $last = array_pop($left);
            throw new DomainException(__('No se puede borrar: quedan :where. Ponlos a cero con Merma o Recuento, o trasládalos, primero.', [
                'where' => $left === [] ? $last : implode(', ', $left).' '.__('y').' '.$last,
            ]));
        }
    }
}
