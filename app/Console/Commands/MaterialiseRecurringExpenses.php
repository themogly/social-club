<?php

namespace App\Console\Commands;

use App\Enums\ExpenseKind;
use App\Models\Expense;
use App\Models\RecurringExpenseRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Materialise due recurring overheads into concrete expenses for the current
 * period. IDEMPOTENT: a unique RecurringExpenseRun marker per (template, period)
 * means a scheduler that fires twice — or a retried run — never double-charges the
 * accounts. Templates are Expense rows with a `recurrence` set; the concrete rows
 * they spawn carry no recurrence and so count in outgoings.
 */
class MaterialiseRecurringExpenses extends Command
{
    protected $signature = 'expenses:materialise-recurring';

    protected $description = 'Create concrete expenses from recurring overhead templates for the current period';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $created = 0;

        Expense::withoutGlobalScopes()->whereNotNull('recurrence')->get()
            ->each(function (Expense $template) use ($now, &$created): void {
                $frequency = strtoupper((string) data_get($template->recurrence, 'frequency', 'MONTHLY'));
                [$periodKey, $incurredOn] = $this->period($frequency, $now);

                if (RecurringExpenseRun::query()
                    ->where('expense_template_id', $template->id)
                    ->where('period_key', $periodKey)->exists()) {
                    return; // already materialised for this period
                }

                try {
                    DB::transaction(function () use ($template, $periodKey, $incurredOn): void {
                        $concrete = Expense::create([
                            'organisation_id' => $template->organisation_id,
                            'location_id' => $template->location_id,
                            'category_id' => $template->category_id,
                            'amount_cents' => $template->amount_cents->cents,
                            'paid_from' => $template->paid_from,
                            'kind' => ExpenseKind::OVERHEAD,
                            'till_session_id' => null,
                            'recurrence' => null,          // concrete, not a template
                            'supplier_id' => $template->supplier_id,
                            'note' => $template->note,
                            'recorded_by' => $template->recorded_by,
                            'incurred_on' => $incurredOn,
                        ]);

                        RecurringExpenseRun::create([
                            'expense_template_id' => $template->id,
                            'period_key' => $periodKey,
                            'created_expense_id' => $concrete->id,
                        ]);
                    });
                    $created++;
                } catch (QueryException) {
                    // unique(template, period) race — another run got there first. Idempotent.
                }
            });

        $this->info("Materialised {$created} recurring expense(s).");

        return self::SUCCESS;
    }

    /**
     * The period key + its first day for a frequency at $now.
     *
     * @return array{0: string, 1: string}
     */
    private function period(string $frequency, CarbonImmutable $now): array
    {
        return match ($frequency) {
            'YEARLY' => [$now->format('Y'), $now->startOfYear()->toDateString()],
            'QUARTERLY' => [$now->format('Y').'-Q'.(int) ceil($now->month / 3), $now->startOfQuarter()->toDateString()],
            default => [$now->format('Y-m'), $now->startOfMonth()->toDateString()], // MONTHLY
        };
    }
}
