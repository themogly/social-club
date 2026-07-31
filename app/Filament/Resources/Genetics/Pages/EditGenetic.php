<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Models\Genetic;
use App\Support\Weight;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditGenetic extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = GeneticResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // A genetic-definition change is audited (prompt 48). Genetic PRICES live in GeneticPrice rows,
    // edited via the GeneticPricesRelationManager and audited separately as genetic.price.updated
    // (prompt 63) — so this audits the definition, not a price.
    protected function beforeSave(): void
    {
        $this->captureAuditDiff();
    }

    protected function afterSave(): void
    {
        $this->writeAuditLog('genetic.updated');
    }

    /**
     * Seed the virtual percent fields from the stored basis points.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Genetic $genetic */
        $genetic = $this->getRecord();
        $data['thc_pct'] = blank($genetic->thc_bp) ? null : $genetic->thc_bp / 100;
        $data['cbd_pct'] = blank($genetic->cbd_bp) ? null : $genetic->cbd_bp / 100;
        $data['grams_per_unit_g'] = blank($genetic->grams_per_unit_cg) ? null : $genetic->grams_per_unit_cg / 100;

        return $data;
    }

    /**
     * Convert the edited percent/grams back to integer basis points / centigrams.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['thc_bp'] = filled($data['thc_pct'] ?? null) ? (int) round_half_up(((float) $data['thc_pct']) * 100) : null;
        $data['cbd_bp'] = filled($data['cbd_pct'] ?? null) ? (int) round_half_up(((float) $data['cbd_pct']) * 100) : null;
        $data['grams_per_unit_cg'] = filled($data['grams_per_unit_g'] ?? null) ? Weight::fromGrams($data['grams_per_unit_g'])->centigrams : null;
        unset($data['thc_pct'], $data['cbd_pct'], $data['grams_per_unit_g']);

        return $data;
    }
}
