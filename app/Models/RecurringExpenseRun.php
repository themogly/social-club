<?php

namespace App\Models;

use Database\Factories\RecurringExpenseRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Idempotency marker for the recurring-expense scheduler (unique per template+period). */
class RecurringExpenseRun extends Model
{
    /** @use HasFactory<RecurringExpenseRunFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'expense_template_id', 'period_key', 'created_expense_id',
    ];

    /** @return BelongsTo<Expense, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_template_id');
    }

    /** @return BelongsTo<Expense, $this> */
    public function createdExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'created_expense_id');
    }
}
