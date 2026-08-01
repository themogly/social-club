<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Filament\Resources\Genetics\GeneticResource;
use App\Support\Weight;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateGenetic extends CreateRecord
{
    protected static string $resource = GeneticResource::class;

    /**
     * Carry the user onward (prompt 93): a genetic alone dispenses NOWHERE — it needs a per-location price
     * and a batch. Land them on the genetic's own page (where the prices relation manager lives) with a
     * notification naming the remaining steps, rather than dropping them on the list one third of the way
     * through. They can still stop: the "Sin precio" flag on the list keeps that safe and visible.
     */
    protected function getRedirectUrl(): string
    {
        return GeneticResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('Genética creada'))
            ->body(__('Para que aparezca en el mostrador, añade un precio por sede y un lote con stock.'));
    }

    /**
     * THC/CBD live as integer basis points (thc_bp / cbd_bp) and the per-unit gram
     * content as integer centigrams (grams_per_unit_cg); the form edits percent and
     * grams. An empty field stays null (unknown), it does not become 0. unit_type is
     * NOT set here — GeneticObserver derives it from product_type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['thc_bp'] = filled($data['thc_pct'] ?? null) ? (int) round_half_up(((float) $data['thc_pct']) * 100) : null;
        $data['cbd_bp'] = filled($data['cbd_pct'] ?? null) ? (int) round_half_up(((float) $data['cbd_pct']) * 100) : null;
        $data['grams_per_unit_cg'] = filled($data['grams_per_unit_g'] ?? null) ? Weight::fromGrams($data['grams_per_unit_g'])->centigrams : null;
        unset($data['thc_pct'], $data['cbd_pct'], $data['grams_per_unit_g']);

        return $data;
    }
}
