<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Actions\Purchases\RecordPurchase;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    /**
     * Never write the Purchase directly: run through RecordPurchase so a batch-linked
     * intake carries cost-per-gram onto the batch and the audit-log entry is written.
     * Money crosses the edge here (euros → integer cents via Money); intake weight
     * crosses as grams → integer centigrams via the shared round_half_up helper.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);

        $options = [
            'location_id' => $data['location_id'] ?? null,
            'paid_cents' => Money::fromEuros((float) ($data['paid_eur'] ?? 0))->cents,
            'items' => $data['items'] ?? null,
            'invoice_path' => $data['invoice_path'] ?? null,
            'purchased_on' => $data['purchased_on'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
        ];

        $grams = $data['intake_grams'] ?? null;
        if (is_numeric($grams)) {
            $options['intake_grams_cg'] = (int) round_half_up((float) $grams * 100);
        }

        return (new RecordPurchase)->handle(
            $supplier,
            Money::fromEuros((float) $data['amount_eur'])->cents,
            $options,
        );
    }
}
