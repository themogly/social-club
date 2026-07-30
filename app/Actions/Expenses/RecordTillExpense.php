<?php

namespace App\Actions\Expenses;

use App\Actions\RecordAuditLog;
use App\Actions\Till\RecordCashMovement;
use App\Enums\CashMovementType;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Enums\TillSessionStatus;
use App\Exceptions\TillClosedException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Petty cash OUT of the open drawer during a shift. It MUST hit the cash
 * reconciliation — a PETTY_CASH cash movement, so TillSummary drops it from the
 * expected drawer cash — or the drawer would look over at cierre. Recorded against
 * the session and attributed to the PIN operator. Above the configurable threshold
 * it needs a separate recorded approval (expenses.approve) before it is settled;
 * the cash still leaves the drawer either way (the money physically moved).
 */
class RecordTillExpense
{
    /**
     * @param  array{note?: ?string, receipt_path?: ?string, operator_id?: ?string}  $options
     */
    public function handle(TillSession $session, ExpenseCategory $category, int $amountCents, User $actor, array $options = []): Expense
    {
        if (! $actor->can('expenses.record')) {
            throw new AuthorizationException('Recording an expense requires the expenses.record permission.');
        }
        if ($amountCents <= 0) {
            throw new RuntimeException('A till expense must be a positive amount.');
        }
        if ($session->status !== TillSessionStatus::OPEN) {
            throw new TillClosedException('Petty cash can only be recorded against an open till session.');
        }

        return DB::transaction(function () use ($session, $category, $amountCents, $actor, $options): Expense {
            $location = Location::withoutGlobalScopes()->findOrFail($session->location_id);

            // The money physically left the drawer — reconcile it immediately.
            (new RecordCashMovement)->handle($session, CashMovementType::PETTY_CASH, $amountCents, [
                'reason' => $options['note'] ?? 'Gasto de caja',
                'operator_id' => $options['operator_id'] ?? $actor->id,
            ]);

            $expense = Expense::create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'category_id' => $category->id,
                'amount_cents' => $amountCents,
                'paid_from' => ExpensePaidFrom::TILL_CASH,
                'kind' => ExpenseKind::TILL,
                'till_session_id' => $session->id,
                'receipt_path' => $options['receipt_path'] ?? null,
                'note' => $options['note'] ?? null,
                'recorded_by' => $actor->id,
                'incurred_on' => now()->toDateString(),
            ]);

            (new RecordAuditLog)->handle('expense.till.recorded', $expense, null, [
                'amount_cents' => $amountCents,
                'category' => $category->name,
                'till_session_id' => $session->id,
            ]);

            return $expense;
        });
    }
}
