<?php

namespace App\Actions\Purchases;

use App\Actions\RecordAuditLog;
use App\Models\Batch;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record a supplier purchase. Where it brings in cannabis it links to the batch
 * intake (prompt 07) and carries cost-per-gram onto that batch, so stock valuation
 * and the purchase-vs-withdrawal reconciliation (prompt 14) rest on a real figure
 * rather than a guess. Amount and paid are integer cents; owing = amount − paid.
 */
class RecordPurchase
{
    /**
     * @param  array{location_id?: ?string, paid_cents?: int, items?: ?array<int, mixed>, invoice_path?: ?string, purchased_on?: ?string, batch_id?: ?string, intake_grams_cg?: ?int}  $options
     */
    public function handle(Supplier $supplier, int $amountCents, array $options = []): Purchase
    {
        if ($amountCents < 0) {
            throw new RuntimeException('A purchase amount cannot be negative.');
        }

        return DB::transaction(function () use ($supplier, $amountCents, $options): Purchase {
            $purchase = Purchase::create([
                'organisation_id' => $supplier->organisation_id,
                'location_id' => $options['location_id'] ?? null,
                'supplier_id' => $supplier->id,
                'amount_cents' => $amountCents,
                'paid_cents' => $options['paid_cents'] ?? 0,
                'items' => $options['items'] ?? null,
                'invoice_path' => $options['invoice_path'] ?? null,
                'purchased_on' => $options['purchased_on'] ?? now()->toDateString(),
                'batch_id' => $options['batch_id'] ?? null,
            ]);

            // Cannabis intake → cost per gram flows onto the batch for stock valuation.
            // cost/g cents = amount_cents ÷ grams = amount_cents × 100 ÷ grams_cg.
            $batchId = $options['batch_id'] ?? null;
            $gramsCg = (int) ($options['intake_grams_cg'] ?? 0);
            if ($batchId !== null && $gramsCg > 0) {
                $batch = Batch::withoutGlobalScopes()->findOrFail($batchId);
                $batch->cost_per_gram_cents = (int) round_half_up($amountCents * 100 / $gramsCg);
                $batch->save();
            }

            (new RecordAuditLog)->handle('purchase.recorded', $purchase, null, [
                'amount_cents' => $amountCents,
                'supplier' => $supplier->name,
                'batch_id' => $batchId,
            ]);

            return $purchase;
        });
    }
}
