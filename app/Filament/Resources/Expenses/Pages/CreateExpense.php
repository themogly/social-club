<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Expenses\RecordOverhead;
use App\Enums\ExpensePaidFrom;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /**
     * Never write the Expense directly: run through RecordOverhead so the
     * never-touch-the-till guarantee and the audit-log entry both hold. Money crosses
     * the edge here (euros → integer cents via Money); a chosen frequency becomes a
     * recurrence array, which makes the row a recurring TEMPLATE. The page is gated on
     * expenses.overheads (ExpenseResource::canCreate) — a manager/staff never reaches it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Organisation $organisation */
        $organisation = Organisation::query()->findOrFail(app(ActiveScope::class)->organisationId());

        /** @var ExpenseCategory $category */
        $category = ExpenseCategory::query()->findOrFail($data['category_id']);

        /** @var User $actor */
        $actor = Auth::user();

        $options = [
            'location_id' => $data['location_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'note' => $data['note'] ?? null,
            'receipt_path' => $data['receipt_path'] ?? null,
            'incurred_on' => $data['incurred_on'] ?? null,
        ];

        $frequency = $data['recurrence_frequency'] ?? null;
        if (is_string($frequency) && $frequency !== '') {
            $options['recurrence'] = ['frequency' => $frequency];
        }

        return (new RecordOverhead)->handle(
            $organisation,
            $category,
            Money::fromEuros((float) $data['amount_eur'])->cents,
            ExpensePaidFrom::from((string) $data['paid_from']),
            $actor,
            $options,
        );
    }

    /** A recurring template is not a concrete outgoing (excluded from the list) — land on the index. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
