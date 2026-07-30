<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Amount and paid round-trip euros ↔ integer cents. The batch link / intake grams are
 * create-only (an intake decision), so editing only revises the money and metadata.
 */
class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Purchase $purchase */
        $purchase = $this->getRecord();
        $data['amount_eur'] = $purchase->amount_cents->cents / 100;
        $data['paid_eur'] = $purchase->paid_cents->cents / 100;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['amount_cents'] = Money::fromEuros((float) ($data['amount_eur'] ?? 0))->cents;
        $data['paid_cents'] = Money::fromEuros((float) ($data['paid_eur'] ?? 0))->cents;
        unset($data['amount_eur'], $data['paid_eur']);

        return $data;
    }
}
