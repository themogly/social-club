<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Exceptions\StockCeilingExceededException;
use App\Models\Batch;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use App\Support\Settings;
use App\Support\StockCeiling;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Move some or all of a batch to another location (prompt 277, Ben's 270) — THE one writer for a transfer: from the
 * grow / central store to a sede ("Asignar a sede"), sede to sede, or back to the store.
 *
 *   · WHOLE (the quantity is everything remaining): the batch itself moves — same id, lote number and history — with
 *     a TRANSFER_OUT at the source and a TRANSFER_IN at the destination, so the move is in the ledger.
 *   · PART: the source is decremented (TRANSFER_OUT) and a CHILD batch is created at the destination (TRANSFER_IN) with
 *     the SAME lote number, genetic, dates, lab report and cost, and `parent_batch_id` = the source — so a gram
 *     dispensed from it is traced back to the original harvest. A child can be transferred again.
 *
 * Both legs go through `RecordStockMovement` (the one stock writer: locked row, signed delta, refuses negative) inside
 * ONE transaction, so a transfer can never race a dispensation for the same grams. Refused: no `stock.transfer`, zero
 * or negative quantity (256), more than remains, the same location, a destination in another organisation, and —
 * when the sede's stock ceiling is set to BLOCK — stock that would push the destination sede over it (the intake rule).
 * Audited as `stock.transferred`.
 */
class TransferBatch
{
    /**
     * @param  int  $quantity  centigrams for a weight batch, whole units for a unit batch
     * @return Batch the batch now holding the moved stock at the destination (the same batch when whole)
     *
     * @throws AuthorizationException
     */
    public function handle(Batch $batch, Location $to, int $quantity, User $actor): Batch
    {
        if (! $actor->can('stock.transfer')) {
            throw new AuthorizationException(__('No tienes permiso para trasladar stock.'));
        }
        // Post-296 audit — and only at a location the actor works at (the owner works at all of them).
        if (! app(LocationSwitcher::class)->canAccess($actor, $batch->location_id)) {
            throw new AuthorizationException(__('Ese lote es de una sede en la que no trabajas.'));
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException(__('La cantidad a trasladar debe ser mayor que cero.'));
        }
        if ($to->id === $batch->location_id) {
            throw new InvalidArgumentException(__('El lote ya está en esa ubicación.'));
        }
        if ($to->organisation_id !== $batch->organisation_id) {
            throw new InvalidArgumentException(__('No se puede trasladar a una ubicación de otra organización.'));
        }

        return DB::transaction(function () use ($batch, $to, $quantity, $actor): Batch {
            /** @var Batch $source */
            $source = Batch::query()->withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $isUnit = $source->isUnitType();
            $remaining = $isUnit ? (int) ($source->remaining_units ?? 0) : $source->remaining_cg->centigrams;

            if ($quantity > $remaining) {
                throw new InvalidArgumentException(__('No puedes trasladar más de lo que queda en el lote.'));
            }

            $this->guardCeiling($source, $to, $quantity, $isUnit);

            $from = (string) $source->location_id;
            $options = ['reason' => __('Traslado'), 'operator_id' => $actor->id, 'reference' => $source->batch_no];

            (new RecordStockMovement)->handle($source, StockMovementType::TRANSFER_OUT, -$quantity, $options);

            if ($quantity === $remaining) {
                // Whole batch: it moves itself — the same id, lote and history.
                Batch::query()->withoutGlobalScopes()->whereKey($source->id)->update(['location_id' => $to->id]);
                $target = Batch::query()->withoutGlobalScopes()->findOrFail($source->id);
            } else {
                $target = Batch::create([
                    'organisation_id' => $source->organisation_id,
                    'genetic_id' => $source->genetic_id,
                    'parent_batch_id' => $source->id,
                    'location_id' => $to->id,
                    'batch_no' => $source->batch_no,
                    'lote_seq' => $source->lote_seq, // the same lote (prompt 298)
                    'label' => $source->label, // the name belongs to the lote (prompt 282)
                    'acquired_or_harvested_on' => $source->acquired_or_harvested_on,
                    'expires_on' => $source->expires_on,
                    'initial_cg' => $isUnit ? null : $quantity,
                    'remaining_cg' => $isUnit ? null : 0,
                    'initial_units' => $isUnit ? $quantity : null,
                    'remaining_units' => $isUnit ? 0 : null,
                    'cost_per_gram_cents' => $source->cost_per_gram_cents,
                    // The child inherits its parent's sale price and stays editable per batch (278); its photos are the
                    // parent's until it gets its own (Batch::displayImages), never copied.
                    'price_per_gram_cents' => $source->price_per_gram_cents,
                    'price_per_unit_cents' => $source->price_per_unit_cents,
                    'price_per_eighth_cents' => $source->price_per_eighth_cents,
                    'lab_report_path' => $source->lab_report_path,
                    'notes' => $source->notes,
                    'status' => BatchStatus::OPEN,
                ]);
            }

            (new RecordStockMovement)->handle($target, StockMovementType::TRANSFER_IN, $quantity, $options);

            (new RecordAuditLog)->handle('stock.transferred', $target, null, [
                'from_location_id' => $from,
                'to_location_id' => $to->id,
                'quantity' => $quantity,
                'unit' => $isUnit ? 'units' : 'cg',
                'source_batch_id' => $source->id,
                'batch_id' => $target->id,
                'whole' => $target->id === $source->id,
            ]);

            return $target->refresh();
        });
    }

    /** Stock entering a SEDE obeys its ceiling as an intake does; the grow / central store has none (277). */
    private function guardCeiling(Batch $source, Location $to, int $quantity, bool $isUnit): void
    {
        if ($to->isStore() || Settings::enforcement('stock', 'ceiling') !== 'BLOCK') {
            return;
        }

        $incomingCg = $isUnit ? $quantity * (int) $source->genetic?->grams_per_unit_cg : $quantity;
        $ceiling = StockCeiling::forLocation($to);

        if ($ceiling['ceiling_cg'] < $ceiling['on_site_cg'] + $incomingCg) {
            throw new StockCeilingExceededException(__('Este traslado superaría el techo de stock de la sede de destino.'));
        }
    }
}
