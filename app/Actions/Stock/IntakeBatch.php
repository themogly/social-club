<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Exceptions\StockCeilingExceededException;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\Support\Weight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record a new batch. Keys off the genetic's unit_type: a WEIGHT genetic takes GRAMS
 * (2 dp) at the edge and stores integer centigrams; a UNIT genetic (preroll/edible)
 * takes whole UNITS. A float grams value is never stored. The opening stock is written
 * as an INTAKE movement (opening balances always enter through the ledger). Exactly one
 * of the cg / units column pairs is populated — the other is set null explicitly.
 *
 * @phpstan-type IntakeData array{grams?: int|float|string, units?: int|string, batch_no?: ?string, cost_per_gram_cents?: int, acquired_or_harvested_on?: mixed, expires_on?: mixed, lab_report_path?: ?string, notes?: ?string, operator_id?: ?string, override?: bool, override_by?: ?User, override_reason?: ?string}
 */
class IntakeBatch
{
    /**
     * @param  IntakeData  $data
     */
    public function handle(Genetic $genetic, Location $location, array $data): Batch
    {
        $isUnit = $genetic->isUnitType();
        $units = $isUnit ? (int) ($data['units'] ?? 0) : null;
        $cg = $isUnit ? null : Weight::fromGrams($data['grams'] ?? 0)->centigrams;

        // Premises stock ceiling (prompt 110): would this intake push the on-site weight over the legal
        // ceiling? WARN → proceed; BLOCK → refuse unless a limits.override holder authorised it with a reason.
        $ceilingOverride = $this->authoriseCeiling($genetic, $location, $cg, $units, $data);

        // Batch + its opening-balance movement are atomic, and new stock entering the premises is
        // audited (prompt 48 — the most traceability-sensitive event in a cannabis club). INSIDE the
        // txn, so a failed audit rolls back the intake (boundary matches CommitStockTake).
        return DB::transaction(function () use ($genetic, $location, $data, $units, $cg, $ceilingOverride): Batch {
            $batch = Batch::create([
                'organisation_id' => $genetic->organisation_id,
                'genetic_id' => $genetic->id,
                'location_id' => $location->id,
                'batch_no' => $data['batch_no'] ?? 'B-'.strtoupper(Str::random(6)),
                'acquired_or_harvested_on' => $data['acquired_or_harvested_on'] ?? now(),
                'expires_on' => $data['expires_on'] ?? null,
                'initial_cg' => $cg,
                'remaining_cg' => $cg,
                'initial_units' => $units,
                'remaining_units' => $units,
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
                'qty_units' => $units,
                'type' => StockMovementType::INTAKE,
                'reason' => 'Alta de lote',
                'operator_id' => $data['operator_id'] ?? Auth::id(),
                'reference' => $batch->batch_no,
            ]);

            (new RecordAuditLog)->handle('batch.intake', $batch, null, array_filter([
                'batch_no' => $batch->batch_no,
                'genetic' => $genetic->name,
                'initial_cg' => $cg,
                'initial_units' => $units,
                'cost_per_gram_cents' => (int) ($data['cost_per_gram_cents'] ?? 0),
            ], fn ($v): bool => $v !== null));

            // A ceiling override is its OWN audit row — a reasoned, permissioned breach of a compliance limit.
            if ($ceilingOverride !== null) {
                (new RecordAuditLog)->handle('stock.ceiling.overridden', $batch, null, $ceilingOverride);
            }

            return $batch;
        });
    }

    /**
     * Enforce the premises stock ceiling for this intake. Returns override metadata to audit when a BLOCK was
     * authorised, or null when no override was needed (within the ceiling, or WARN mode).
     *
     * @param  IntakeData  $data
     * @return array<string, mixed>|null
     */
    private function authoriseCeiling(Genetic $genetic, Location $location, ?int $cg, ?int $units, array $data): ?array
    {
        $incomingCg = $cg ?? (($units ?? 0) * (int) $genetic->grams_per_unit_cg);
        if ($incomingCg <= 0) {
            return null;
        }

        $ceiling = StockCeiling::forLocation($location);
        $projected = $ceiling['on_site_cg'] + $incomingCg;
        if ($projected <= $ceiling['ceiling_cg']) {
            return null; // within the ceiling
        }

        if (Settings::enforcement('stock', 'ceiling') === 'WARN') {
            return null; // allowed — surfaced by the dashboard indicator and the intake form
        }

        // BLOCK — the same override contract as a member limit: permission-gated, reasoned, audited.
        $by = $data['override_by'] ?? null;
        $reason = trim((string) ($data['override_reason'] ?? ''));

        if (empty($data['override']) || ! $by instanceof User) {
            throw new StockCeilingExceededException('This intake would exceed the premises stock ceiling.');
        }
        if (! $by->can('limits.override')) {
            throw new AuthorizationException('Overriding the stock ceiling requires the limits.override permission.');
        }
        if ($reason === '') {
            throw new StockCeilingExceededException('A stock-ceiling override requires a reason.');
        }

        return [
            'override_by' => $by->id,
            'reason' => $reason,
            'projected_on_site_cg' => $projected,
            'ceiling_cg' => $ceiling['ceiling_cg'],
        ];
    }
}
