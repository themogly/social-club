<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A descriptive sub-classification for a CONCENTRATE genetic (hash, rosin, shatter,
 * wax, live resin). Nullable and cosmetic only — concentrates are ONE top-level
 * {@see ProductType} (WEIGHT), never four separate types; this just labels the form.
 */
enum ConcentrateSubtype: string implements HasLabel
{
    case HASH = 'HASH';
    case ROSIN = 'ROSIN';
    case SHATTER = 'SHATTER';
    case WAX = 'WAX';
    case LIVE_RESIN = 'LIVE_RESIN';

    public function label(): string
    {
        return match ($this) {
            self::HASH => __('Hachís'),
            self::ROSIN => __('Rosin'),
            self::SHATTER => __('Shatter'),
            self::WAX => __('Wax'),
            self::LIVE_RESIN => __('Live resin'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
