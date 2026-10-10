<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountKind: string implements HasLabel
{
    case STAFF = 'STAFF';
    case LOCAL = 'LOCAL';
    case CONCESSION = 'CONCESSION';
    case THERAPEUTIC = 'THERAPEUTIC';
    case CUSTOM = 'CUSTOM';

    public function label(): string
    {
        return match ($this) {
            self::STAFF => __('Personal'),
            self::LOCAL => __('Local'),
            self::CONCESSION => __('Concesión'),
            self::THERAPEUTIC => __('Terapéutico'),
            self::CUSTOM => __('Personalizado'),
        };
    }

    /**
     * Prompt 382 — STAFF and LOCAL are replaced by the «Personal» and «Local» price lists: no new discount of either kind can
     * be created. The kinds stay so existing discounts keep working and history reads.
     */
    public function isRetired(): bool
    {
        return $this === self::STAFF || $this === self::LOCAL;
    }

    /**
     * The kinds a discount form offers: every kind still created, plus the record's own (a retired kind stays editable on
     * the discount that already has it).
     *
     * @return array<string, string>
     */
    public static function formOptions(?self $current = null): array
    {
        return collect(self::cases())
            ->filter(fn (self $kind): bool => ! $kind->isRetired() || $kind === $current)
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }

    /**
     * Prompt 375 — what a report calls a stored line's `discount_kind`: a kind's label, «Tarifa» for a tier discount (which has
     * no Discount row), and «Sin clasificar (anterior a hoy)» for a line written before the kind was stored (no backfill).
     */
    public static function reportLabel(?string $kind): string
    {
        return match (true) {
            $kind === null || $kind === '' => __('Sin clasificar (anterior a hoy)'),
            $kind === 'TIER' => __('Tarifa'),
            default => self::tryFrom($kind)?->label() ?? __('Sin clasificar (anterior a hoy)'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
