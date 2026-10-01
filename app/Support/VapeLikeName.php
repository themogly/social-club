<?php

namespace App\Support;

/**
 * Prompt 347 (Liam: "Vape category for products doesn't seem to be used") — names that look like a vape, in one place
 * for the strain form's hint, the bar product's warning and `csc:find-vape-like`.
 */
class VapeLikeName
{
    /** A vape by its name — the strain form hints «¿Es un vapeador?» when its type says otherwise. */
    private const VAPE = '/\b(vape|vapes|vaper|vapeador|cartucho|cartuchos|cartridge|cartridges|pod|pods|desechable|desechables|disposable|disposables|pen)\b/iu';

    /** …plus the words that say cannabis — a bar product named so is probably a vape entered on the wrong ledger. */
    private const CANNABIS = '/\b(thc|cbd|cannabis|weed)\b/iu';

    public static function strain(?string $name): bool
    {
        return filled($name) && preg_match(self::VAPE, (string) $name) === 1;
    }

    public static function barProduct(?string $name): bool
    {
        return self::strain($name) || (filled($name) && preg_match(self::CANNABIS, (string) $name) === 1);
    }
}
