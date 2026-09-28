<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The product type as STAFF pick and read it (prompt 276 / Ben's 269). Hash is the club's commonest
 * extract, and "Extracto → Hachís" was a path nobody knew to take — to the owner there was "no hash". So
 * Hachís is a first-level choice here, beside Flor / Extracto / Preliado / Comestible.
 *
 * UI only: this is never stored. It maps onto the real model — HASH is `ProductType::CONCENTRATE` +
 * `ConcentrateSubtype::HASH` — so the two dispensing paths (the derived UnitType every limit, ceiling, stock
 * and report branches on) are untouched, and every existing CONCENTRATE/HASH row reads as Hachís with no
 * migration. The four other cases share their value with the ProductType they stand for.
 */
enum ProductTypeChoice: string implements HasLabel
{
    case FLOWER = 'FLOWER';
    case HASH = 'HASH';
    case CONCENTRATE = 'CONCENTRATE';
    case PREROLL = 'PREROLL';
    case EDIBLE = 'EDIBLE';

    /** The choice a stored (product_type, concentrate_subtype) pair reads as. Accepts enums or raw column strings. */
    public static function of(ProductType|string|null $type, ConcentrateSubtype|string|null $subtype = null): self
    {
        $type = $type instanceof ProductType ? $type : (ProductType::tryFrom((string) $type) ?? ProductType::FLOWER);
        $subtype = $subtype instanceof ConcentrateSubtype ? $subtype : ConcentrateSubtype::tryFrom((string) $subtype);

        return $type === ProductType::CONCENTRATE && $subtype === ConcentrateSubtype::HASH
            ? self::HASH
            : self::from($type->value);
    }

    /** The real product type this choice is stored as. */
    public function productType(): ProductType
    {
        return $this === self::HASH ? ProductType::CONCENTRATE : ProductType::from($this->value);
    }

    public function unitType(): UnitType
    {
        return $this->productType()->unitType();
    }

    /**
     * The stored columns for this choice. Hachís fixes the subtype; Extracto keeps the one picked (never HASH —
     * that is the other choice); every other type has none (the observer clears it too).
     *
     * @return array{product_type: string, concentrate_subtype: ?string}
     */
    public function attributes(?string $subtype = null): array
    {
        return [
            'product_type' => $this->productType()->value,
            'concentrate_subtype' => match ($this) {
                self::HASH => ConcentrateSubtype::HASH->value,
                self::CONCENTRATE => $subtype === ConcentrateSubtype::HASH->value ? null : (ConcentrateSubtype::tryFrom((string) $subtype)?->value),
                default => null,
            },
        ];
    }

    /** @return array<string, string> the picker's options, in the order staff read them */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }

    /** @return array<string, string> the Extracto subtypes — Hachís is its own choice, so never offered here */
    public static function extractSubtypeOptions(): array
    {
        return collect(ConcentrateSubtype::cases())
            ->reject(fn (ConcentrateSubtype $case): bool => $case === ConcentrateSubtype::HASH)
            ->mapWithKeys(fn (ConcentrateSubtype $case): array => [$case->value => $case->label()])
            ->all();
    }

    public function label(): string
    {
        return $this === self::HASH ? ConcentrateSubtype::HASH->label() : $this->productType()->label();
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
