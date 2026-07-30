<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\RecurringExpenseRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringExpenseRun>
 */
class RecurringExpenseRunFactory extends Factory
{
    protected $model = RecurringExpenseRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_template_id' => Expense::factory(),
            'period_key' => fake()->numerify('202#-0#'),
            'created_expense_id' => null,
        ];
    }
}
