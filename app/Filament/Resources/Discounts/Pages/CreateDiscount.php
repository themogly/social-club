<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Resources\Discounts\DiscountResource;
use App\Filament\Resources\Discounts\Schemas\DiscountForm;
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
        // Stamped on CREATE only. Deliberately not inside normalise(), which EditDiscount also calls —
        // stamping there would silently convert every legacy fixed-amount or bar-scoped row the moment
        // somebody edited its name, which would take the bar's discounts out with it.
        $data['mode'] = DiscountForm::mode()->value;
        $data['applies_to'] = DiscountForm::appliesTo()->value;

        return self::normalise($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        // The percentage conversion ONLY (prompt 168). The old version also chose the mode from the
        // submitted value and, when it was not PERCENT, cast a missing amount through
        // `(float) ($data['value_eur'] ?? 0)` — which is how a live, assignable discount worth 0 got
        // created. The field is now required with a 0.01 floor; this keeps the conversion honest for
        // anything that reaches it.
        $data['value_bp'] = (int) round_half_up(((float) ($data['value_pct'] ?? 0)) * 100);
        $data['value_cents'] = null;

        unset($data['value_pct'], $data['value_eur']);

        return $data;
    }
}
