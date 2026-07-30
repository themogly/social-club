<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Actions\Stock\IntakeBatch;
use App\Filament\Resources\Batches\BatchResource;
use App\Models\Genetic;
use App\Models\Location;
use App\Support\ActiveScope;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBatch extends CreateRecord
{
    protected static string $resource = BatchResource::class;

    /**
     * Intake never writes remaining_cg directly: it runs through IntakeBatch, which
     * converts grams → integer centigrams and records the opening INTAKE movement.
     * Weight and money cross the edge here (grams, euros) and are converted to
     * integer units. The batch is created at the active location.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Genetic $genetic */
        $genetic = Genetic::query()->findOrFail($data['genetic_id']);

        $locationId = app(ActiveScope::class)->locationId() ?? Location::query()->value('id');
        /** @var Location $location */
        $location = Location::query()->findOrFail($locationId);

        $intake = [
            'cost_per_gram_cents' => (int) round(((float) ($data['cost_per_gram_eur'] ?? 0)) * 100),
            'acquired_or_harvested_on' => $data['acquired_or_harvested_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'lab_report_path' => $data['lab_report_path'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        // Intake in the genetic's own unit — grams for WEIGHT, whole units for UNIT.
        if ($genetic->isUnitType()) {
            $intake['units'] = (int) ($data['units'] ?? 0);
        } else {
            $intake['grams'] = $data['grams'];
        }

        return (new IntakeBatch)->handle($genetic, $location, $intake);
    }
}
