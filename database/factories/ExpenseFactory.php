<?php

namespace Database\Factories;

use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => null,
            'category_id' => ExpenseCategory::factory(),
            'amount_cents' => fake()->numberBetween(1000, 100000),
            'paid_from' => ExpensePaidFrom::TILL_CASH,
            'kind' => ExpenseKind::OVERHEAD,
            'till_session_id' => null,
            'recurrence' => null,
            'receipt_path' => null,
            'recorded_by' => null,
            'approved_by' => null,
            'approved_at' => null,
            'incurred_on' => now(),
        ];
    }
}
