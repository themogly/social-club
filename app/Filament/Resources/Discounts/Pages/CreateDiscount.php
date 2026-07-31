<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Enums\DiscountMode;
use App\Filament\Resources\Discounts\DiscountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDiscount extends CreateRecord
{
    protected static string $resource = DiscountResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normalise($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        if (($data['mode'] ?? null) === DiscountMode::PERCENT->value) {
            $data['value_bp'] = (int) round_half_up(((float) ($data['value_pct'] ?? 0)) * 100);
            $data['value_cents'] = null;
        } else {
            $data['value_cents'] = (int) round_half_up(((float) ($data['value_eur'] ?? 0)) * 100);
            $data['value_bp'] = null;
        }

        unset($data['value_pct'], $data['value_eur']);

        return $data;
    }
}
