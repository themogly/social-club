<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Filament\Resources\Genetics\GeneticResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGenetic extends CreateRecord
{
    protected static string $resource = GeneticResource::class;

    /**
     * THC/CBD live as integer basis points (thc_bp / cbd_bp); the form edits percent.
     * An empty field stays null (unknown), it does not become 0.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['thc_bp'] = filled($data['thc_pct'] ?? null) ? (int) round(((float) $data['thc_pct']) * 100) : null;
        $data['cbd_bp'] = filled($data['cbd_pct'] ?? null) ? (int) round(((float) $data['cbd_pct']) * 100) : null;
        unset($data['thc_pct'], $data['cbd_pct']);

        return $data;
    }
}
