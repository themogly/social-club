<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Prompt 382 — the three price lists every batch carries (Ben: "standard / local / staff — just them 3 constants"). A tier
 * picks one (`membership_tiers.price_list`); it is an enum so a list can never be renamed, deleted or added to. Tiers are
 * not the lists themselves: a club may run more tiers than lists (Terapéutico at a lower fee and standard prices), because
 * tiers already carry fees and limits that differ.
 */
enum PriceList: string implements HasLabel
{
    case STANDARD = 'STANDARD';
    case LOCAL = 'LOCAL';
    case STAFF = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => __('Estándar'),
            self::LOCAL => __('Local'),
            self::STAFF => __('Personal'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** The column prefix of this list's batch prices: '' for the standard ones, 'local_' / 'staff_' for the others. */
    public function columnPrefix(): string
    {
        return match ($this) {
            self::STANDARD => '',
            self::LOCAL => 'local_',
            self::STAFF => 'staff_',
        };
    }

    /** The setting holding the % a blank price of this list takes off the standard one (null for the standard list). */
    public function defaultDiscountSetting(): ?string
    {
        return match ($this) {
            self::STANDARD => null,
            self::LOCAL => 'price_list_default_discount_pct_local',
            self::STAFF => 'price_list_default_discount_pct_staff',
        };
    }

    /** What a line priced on this list records as `discount_kind` (375), so the reports keep their lines. */
    public function discountKind(): ?string
    {
        return match ($this) {
            self::STANDARD => null,
            self::LOCAL => DiscountKind::LOCAL->value,
            self::STAFF => DiscountKind::STAFF->value,
        };
    }
}
