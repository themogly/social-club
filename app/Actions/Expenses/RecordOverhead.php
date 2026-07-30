<?php

namespace App\Actions\Expenses;

use App\Actions\RecordAuditLog;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * An overhead paid OUTSIDE the till by the treasurer — rent, utilities, refurb. It
 * must NEVER touch a till session or the cash-up (no cash movement, no
 * till_session_id — this is the flow that gets wired wrong), but it must appear in
 * the period's outgoings and the category breakdown. Owner/treasurer only
 * (expenses.overheads). A `recurrence` array makes the row a TEMPLATE
 * (scopeConcrete excludes it from outgoings); the scheduler materialises concrete
 * overheads from it.
 */
class RecordOverhead
{
    /**
     * @param  array{location_id?: ?string, supplier_id?: ?string, note?: ?string, receipt_path?: ?string, incurred_on?: ?string, recurrence?: ?array<string, mixed>}  $options
     */
    public function handle(Organisation $organisation, ExpenseCategory $category, int $amountCents, ExpensePaidFrom $paidFrom, User $actor, array $options = []): Expense
    {
        if (! $actor->can('expenses.overheads')) {
            throw new AuthorizationException('Recording an overhead requires the expenses.overheads permission.');
        }
        if ($amountCents <= 0) {
            throw new RuntimeException('An overhead must be a positive amount.');
        }
        if ($paidFrom === ExpensePaidFrom::TILL_CASH) {
            throw new RuntimeException('An overhead is paid outside the till — use petty cash for drawer spend.');
        }

        $expense = Expense::create([
            'organisation_id' => $organisation->id,
            'location_id' => $options['location_id'] ?? null,
            'category_id' => $category->id,
            'amount_cents' => $amountCents,
            'paid_from' => $paidFrom,
            'kind' => ExpenseKind::OVERHEAD,
            'till_session_id' => null,      // NEVER — an overhead does not touch the drawer
            'recurrence' => $options['recurrence'] ?? null,
            'supplier_id' => $options['supplier_id'] ?? null,
            'receipt_path' => $options['receipt_path'] ?? null,
            'note' => $options['note'] ?? null,
            'recorded_by' => $actor->id,
            'incurred_on' => $options['incurred_on'] ?? now()->toDateString(),
        ]);

        (new RecordAuditLog)->handle('expense.overhead.recorded', $expense, null, [
            'amount_cents' => $amountCents,
            'category' => $category->name,
            'paid_from' => $paidFrom->value,
            'recurring' => ($options['recurrence'] ?? null) !== null,
        ]);

        return $expense;
    }
}
