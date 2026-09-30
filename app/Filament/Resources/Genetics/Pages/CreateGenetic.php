<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Genetics\GeneticResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Prompt 320 (Ben) — creating a strain is the strain only: the same `GeneticForm` as *Editar*, no quantity, sede, price
 * or batch photo. Stock and its price have their own tools now (*Crear lote* with its split, 303; *Añadir existencias en
 * otra sede*, 305; the batch *Precio*), so the old add-strain wizard (247) only duplicated them and forced every new
 * strain to have stock at birth. After saving, the list (295) and a notification whose button opens *Crear lote* with
 * this strain chosen (`?genetic=`, {@see CreateBatch}).
 */
class CreateGenetic extends CreateRecord
{
    use ReturnsToList;

    protected static string $resource = GeneticResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return EditGenetic::toStored($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()
            ->title(__('Genética creada. Añade existencias con «Crear lote».'))
            ->actions([
                Action::make('createBatch')->label(__('Crear lote'))->button()
                    ->url(BatchResource::getUrl('create', ['genetic' => $this->getRecord()->getKey()])),
            ]);
    }
}
