<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Actions\Stock\RenameBatchLote;
use App\Filament\Resources\Batches\BatchActions;
use App\Filament\Resources\Batches\BatchResource;
use App\Models\Batch;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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
            // Prompt 302 — moving the stock is the page's main job for a store batch: the primary button, the list's action.
            BatchActions::transfer(),
            // Prompt 305 — staged stock entry: the lote at another location, and a weigh-up count of this part.
            BatchActions::addParts()->color('gray'),
            BatchActions::recount()->color('gray'),
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

    /**
     * The name (prompt 282) is not a column of THIS row alone: it belongs to the lote, so it goes through
     * `RenameBatchLote` (every part, one audit entry) and the rest of the form saves as before. When the lote has more
     * than one part, the person is told where the name now applies.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $label = $data['label'] ?? null;
        unset($data['label']);

        $record->update($data);

        /** @var Batch $record */
        $parts = (new RenameBatchLote)->handle($record, $label);

        if ($parts->count() > 1) {
            Notification::make()
                ->info()
                ->title(__('El nombre se aplica a las :count partes de este lote (:sedes).', [
                    'count' => $parts->count(),
                    'sedes' => $parts->map(fn (Batch $part): string => (string) $part->location?->name)->unique()->implode(', '),
                ]))
                ->send();
        }

        return $record;
    }
}
