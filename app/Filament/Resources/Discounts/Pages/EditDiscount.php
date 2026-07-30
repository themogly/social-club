<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Resources\Discounts\DiscountResource;
use App\Models\Discount;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscount extends EditRecord
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof Discount) {
            $data['value_pct'] = $record->value_bp !== null ? $record->value_bp / 100 : null;
            $data['value_eur'] = $record->value_cents !== null ? $record->value_cents->cents / 100 : null;
        }

        // value_cents is a Money cast object — it cannot live in Livewire form state
        // (the virtual value_eur field carries it).
        unset($data['value_cents']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateDiscount::normalise($data);
    }
}
