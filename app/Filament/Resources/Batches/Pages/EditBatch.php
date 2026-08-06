<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Batch stock is NOT editable here and never has been: quantity is set once at intake
 * (IntakeBatch) and moves thereafter only through the ledger (RecordStockMovement). The form
 * offers cost, dates, lab report and notes — `initial_cg` and `remaining_cg` are not fields.
 *
 * They still had to be removed from the FILL, because Filament seeds form state from the whole
 * record. Both columns cast through WeightCast, so two `Weight` value objects landed in a public
 * Livewire array property and `dehydrateProperties()` threw during mount — "Property type not
 * supported: [{"centigrams":10000}]" — making an existing batch impossible to open. The batch was
 * always fine and its data sound; only the edit page was broken (prompt 166).
 */
class EditBatch extends EditRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Unlike the money pages there is no virtual counterpart to seed here: stock is deliberately
     * read-only, so the cast columns are simply dropped rather than round-tripped through a
     * grams field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['initial_cg'], $data['remaining_cg']);

        return $data;
    }
}
