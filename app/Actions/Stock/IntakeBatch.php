<?php

namespace App\Actions\Stock;

use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\StockMovement;
use App\Support\Weight;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Record a new batch. Weight comes in as GRAMS (2 dp) at the edge and is converted
 * to integer centigrams by this Action — a float grams value is never stored. The
 * opening stock is written as an INTAKE movement (opening balances always enter
 * through the ledger, never free-typed).
 *
 * @phpstan-type IntakeData array{grams: int|float|string, batch_no?: ?string, cost_per_gram_cents?: int, acquired_or_harvested_on?: mixed, expires_on?: mixed, lab_report_path?: ?string, notes?: ?string, operator_id?: ?string}
 */
class IntakeBatch
{
    /**
     * @param  IntakeData  $data
     */
    public function handle(Genetic $genetic, Location $location, array $data): Batch
    {
        $cg = Weight::fromGrams($data['grams'])->centigrams;

        $batch = Batch::create([
            'organisation_id' => $genetic->organisation_id,
            'genetic_id' => $genetic->id,
            'location_id' => $location->id,
            'batch_no' => $data['batch_no'] ?? 'B-'.strtoupper(Str::random(6)),
            'acquired_or_harvested_on' => $data['acquired_or_harvested_on'] ?? now(),
            'expires_on' => $data['expires_on'] ?? null,
            'initial_cg' => $cg,
            'remaining_cg' => $cg,
            'cost_per_gram_cents' => $data['cost_per_gram_cents'] ?? 0,
            'lab_report_path' => $data['lab_report_path'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => BatchStatus::OPEN,
        ]);

        StockMovement::create([
            'organisation_id' => $batch->organisation_id,
            'location_id' => $location->id,
            'stockable_type' => Batch::class,
            'stockable_id' => $batch->id,
            'qty_cg' => $cg,
            'type' => StockMovementType::INTAKE,
            'reason' => 'Alta de lote',
            'operator_id' => $data['operator_id'] ?? Auth::id(),
            'reference' => $batch->batch_no,
        ]);

        return $batch;
    }
}
