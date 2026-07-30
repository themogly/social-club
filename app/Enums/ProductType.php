<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of cannabis product a genetic is. Drives a DERIVED, stored
 * {@see UnitType} ({@see self::unitType()}) — and it is the UnitType, never this,
 * that every limit/ceiling/stock/report path branches on (two paths, not four):
 *
 *  | product_type | unit_type | dispensed as                                        |
 *  |--------------|-----------|-----------------------------------------------------|
 *  | FLOWER       | WEIGHT    | grams (existing, unchanged)                         |
 *  | CONCENTRATE  | WEIGHT    | grams (hash/rosin/etc. — one type + concentrate_subtype) |
 *  | PREROLL      | UNIT      | units (fixed gram content each)                     |
 *  | EDIBLE       | UNIT      | units (fixed gram-equivalent + thc_mg_per_unit)     |
 */
enum ProductType: string implements HasLabel
{
    case FLOWER = 'FLOWER';
    case CONCENTRATE = 'CONCENTRATE';
    case PREROLL = 'PREROLL';
    case EDIBLE = 'EDIBLE';

    /** The stored unit_type this product type implies. Weight for flower/concentrate; units otherwise. */
    public function unitType(): UnitType
    {
        return match ($this) {
            self::FLOWER, self::CONCENTRATE => UnitType::WEIGHT,
            self::PREROLL, self::EDIBLE => UnitType::UNIT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FLOWER => __('Flor'),
            self::CONCENTRATE => __('Extracto'),
            self::PREROLL => __('Preliado'),
            self::EDIBLE => __('Comestible'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
