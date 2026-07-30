<?php

namespace App\Actions\Expenses;

use App\Actions\RecordAuditLog;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Approve an expense — a RECORDED action with an approver and a timestamp, never a
 * silent status flip. Required above the configurable threshold (expenses.approve).
 */
class ApproveExpense
{
    public function handle(Expense $expense, User $approver): Expense
    {
        if (! $approver->can('expenses.approve')) {
            throw new AuthorizationException('Approving an expense requires the expenses.approve permission.');
        }

        $expense->update([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        (new RecordAuditLog)->handle('expense.approved', $expense, null, [
            'approved_by' => $approver->id,
            'amount_cents' => $expense->amount_cents->cents,
        ]);

        return $expense;
    }
}
