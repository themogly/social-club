<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;

/**
 * Expense categories are administered under `expenses.categories`. Server-side —
 * the Filament resource authorises through this policy, never by hiding a button.
 */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.categories');
    }

    public function view(User $user, ExpenseCategory $model): bool
    {
        return $user->can('expenses.categories');
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.categories');
    }

    public function update(User $user, ExpenseCategory $model): bool
    {
        return $user->can('expenses.categories');
    }

    public function delete(User $user, ExpenseCategory $model): bool
    {
        return $user->can('expenses.categories');
    }
}
