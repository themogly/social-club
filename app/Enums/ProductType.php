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
 *  | HASH         | WEIGHT    | grams (prompt 280 — its own type, the owner's call)  |
 *  | CONCENTRATE  | WEIGHT    | grams (rosin/shatter/etc. — one type + concentrate_subtype) |
 *  | PREROLL      | UNIT      | units (fixed gram content each)                     |
 *  | EDIBLE       | UNIT      | units (fixed gram-equivalent + thc_mg_per_unit)     |
 *  | VAPE         | UNIT      | units (oil weight per cartridge, prompt 328)        |
 */
enum ProductType: string implements HasLabel
{
    case FLOWER = 'FLOWER';
    case HASH = 'HASH';
    case CONCENTRATE = 'CONCENTRATE';
    case PREROLL = 'PREROLL';
    case EDIBLE = 'EDIBLE';
    // Prompt 328 — a cartridge or disposable: dispensed per UNIT, counted by its oil weight (grams_per_unit_cg), like a
    // pre-roll. OVERNIGHT-DEFAULT — CONFIRM with the owner: some clubs dispense vape oil by weight; that is this one line
    // in unitType() moving VAPE to WEIGHT.
    case VAPE = 'VAPE';

    /** The stored unit_type this product type implies. Weight for flower/concentrate; units otherwise. */
    public function unitType(): UnitType
    {
        return match ($this) {
            self::FLOWER, self::HASH, self::CONCENTRATE => UnitType::WEIGHT,
            self::PREROLL, self::EDIBLE, self::VAPE => UnitType::UNIT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FLOWER => __('Flor'),
            self::HASH => __('Hachís'),
            self::CONCENTRATE => __('Extracto'),
            self::PREROLL => __('Preliado'),
            self::EDIBLE => __('Comestible'),
            self::VAPE => __('Vapeador'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
