<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a genetic is dispensed and how its stock/limits are counted. DERIVED from and
 * stored alongside {@see ProductType} (never user-entered — set by GeneticObserver),
 * mirroring the qty_cg/qty_units precedent. **Everything downstream branches on THIS,
 * not on product_type** — two paths, not four:
 *
 *  - WEIGHT → dispensed in grams (stored as integer centigrams). FLOWER, CONCENTRATE.
 *  - UNIT   → dispensed in whole units (a fixed gram content each). PREROLL, EDIBLE.
 */
enum UnitType: string implements HasLabel
{
    case WEIGHT = 'WEIGHT';
    case UNIT = 'UNIT';

    public function label(): string
    {
        return match ($this) {
            self::WEIGHT => __('Por peso'),
            self::UNIT => __('Por unidad'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
