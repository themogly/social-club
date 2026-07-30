<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Filament\Resources\Genetics\GeneticResource;
use App\Support\Weight;
use Filament\Resources\Pages\CreateRecord;

class CreateGenetic extends CreateRecord
{
    protected static string $resource = GeneticResource::class;

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
        $data['thc_bp'] = filled($data['thc_pct'] ?? null) ? (int) round(((float) $data['thc_pct']) * 100) : null;
        $data['cbd_bp'] = filled($data['cbd_pct'] ?? null) ? (int) round(((float) $data['cbd_pct']) * 100) : null;
        $data['grams_per_unit_cg'] = filled($data['grams_per_unit_g'] ?? null) ? Weight::fromGrams($data['grams_per_unit_g'])->centigrams : null;
        unset($data['thc_pct'], $data['cbd_pct'], $data['grams_per_unit_g']);

        return $data;
    }
}
