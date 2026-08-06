<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Enums\DiscountMode;
use App\Filament\Resources\Discounts\DiscountResource;
use App\Models\Discount;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscount extends EditRecord
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
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
        $record = $this->getRecord();

        // A legacy fixed-amount row keeps its amount and its scope. The form cannot express a fixed
        // amount any more (prompt 168), so editing one must round-trip its value untouched rather than
        // convert it to a percentage of whatever happens to be in the box.
        if ($record instanceof Discount && $record->mode !== DiscountMode::PERCENT) {
            unset($data['value_pct'], $data['value_eur'], $data['value_bp'], $data['value_cents'], $data['mode'], $data['applies_to']);

            return $data;
        }

        // Editing never restamps mode/applies_to either — an existing BOTH/ARTICLE row must keep
        // discounting the bar after somebody corrects its name.
        unset($data['mode'], $data['applies_to']);

        return CreateDiscount::normalise($data);
    }
}
