<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Support\ActiveScope;

/**
 * Expenses split into two flows with different gates. Petty cash (TILL) is
 * staff-recordable (expenses.record); overheads are treasurer-only
 * (expenses.overheads) — the ability that MUST be refused to managers and staff so
 * the cash-up and the overhead ledger never get wired together. Approval is its own
 * permission. Every ability is org-scoped through the active scope (User has no
 * organisation column — mirror DispensationPolicy).
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['expenses.record', 'expenses.overheads', 'expenses.approve']);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user)
            && $expense->organisation_id === app(ActiveScope::class)->organisationId();
    }

    /** Petty cash — recordable by counter staff against the open drawer. */
    public function create(User $user): bool
    {
        return $user->can('expenses.record');
    }

    /** Overheads — treasurer only. Refused to managers and staff (denial-tested). */
    public function manageOverheads(User $user): bool
    {
        return $user->can('expenses.overheads');
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $user->can('expenses.approve') && $this->view($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->can('expenses.overheads') && $this->view($user, $expense);
    }
}
