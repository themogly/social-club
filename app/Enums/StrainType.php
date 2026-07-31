<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The strain variety of a genetic — sativa / indica / hybrid (prompt 66). Unlike {@see ProductType}
 * (what the product IS) and {@see CultivationType} (how it was grown), this is a genuinely orthogonal
 * axis: a FLOWER can be sativa or indica. A user-set property of the Genetic (org-wide), NOT observer-
 * derived. Nullable — an edible or a CBD-dominant variety may legitimately have none.
 */
enum StrainType: string implements HasLabel
{
    case SATIVA = 'SATIVA';
    case INDICA = 'INDICA';
    case HYBRID = 'HYBRID';

    public function label(): string
    {
        return match ($this) {
            self::SATIVA => __('Sativa'),
            self::INDICA => __('Índica'),
            self::HYBRID => __('Híbrida'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
