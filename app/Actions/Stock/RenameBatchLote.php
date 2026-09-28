<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Models\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rename a batch (prompt 282) — and with it every part of its lote: the name belongs to the lote (same organisation,
 * strain and lote number), so the two halves of a transferred harvest can never drift apart. One write, one audit
 * entry (`batch.label.changed`, old and new name, the ids it reached), inside one transaction. The lote number itself
 * is never touched — it is the traceability key.
 */
class RenameBatchLote
{
    /**
     * @return Collection<int, Batch> the parts renamed (this batch included), empty when the name did not change
     */
    public function handle(Batch $batch, ?string $label): Collection
    {
        $new = filled($label) ? trim((string) $label) : null;
        $old = $batch->getRawOriginal('label');

        if ($new === $old) {
            return collect();
        }

        return DB::transaction(function () use ($batch, $new, $old): Collection {
            $parts = $batch->lotePartsQuery()->with('location')->lockForUpdate()->get();
            $batch->lotePartsQuery()->update(['label' => $new, 'updated_at' => now()]);

            (new RecordAuditLog)->handle('batch.label.changed', $batch, ['label' => $old], [
                'label' => $new,
                'batch_ids' => $parts->pluck('id')->all(),
            ]);

            $batch->setRawAttributes(array_merge($batch->getAttributes(), ['label' => $new]), true);

            return $parts;
        });
    }
}
