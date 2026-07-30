<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Overheads only (ExpenseResource::canEdit gates on expenses.overheads, and the
 * table only exposes Edit for OVERHEAD rows). Amount round-trips euros ↔ integer
 * cents; an overhead never touches a till, so a plain update is safe.
 */
class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Seed the virtual euro field from the stored integer cents.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Expense $expense */
        $expense = $this->getRecord();
        $data['amount_eur'] = $expense->amount_cents->cents / 100;

        // A Money cast object cannot live in Livewire form state — the virtual euro
        // field carries the value instead.
        unset($data['amount_cents']);

        return $data;
    }

    /**
     * Convert the edited euros back to integer cents.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['amount_cents'] = Money::fromEuros((float) ($data['amount_eur'] ?? 0))->cents;
        unset($data['amount_eur']);

        return $data;
    }
}
