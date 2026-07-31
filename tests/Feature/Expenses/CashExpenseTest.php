<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expenses\RecordOverhead;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Enums\Role;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 67 — cash that never touched the till (a supplier on delivery, rent, a tradesman, the owner's
 * pocket) can now be recorded in the admin form as ExpensePaidFrom::CASH, WITHOUT a cash movement or a
 * till session — distinct from petty cash (TILL_CASH), which stays counter-only so the arqueo reconciles.
 */
class CashExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::factory()->create([
            'organisation_id' => $this->org->id, 'default_kind' => ExpenseKind::OVERHEAD, 'active' => true,
        ]);
    }

    public function test_a_cash_expense_never_touches_a_till_or_a_cash_movement(): void
    {
        $expense = (new RecordOverhead)->handle($this->org, $this->category(), 5000, ExpensePaidFrom::CASH, $this->owner(), [
            'location_id' => $this->location->id, 'incurred_on' => now(),
        ]);

        $this->assertSame(ExpensePaidFrom::CASH, $expense->paid_from);
        $this->assertNull($expense->till_session_id);           // no drawer session
        $this->assertSame(0, CashMovement::query()->count());   // no cash movement — arqueo untouched
    }

    public function test_the_admin_overhead_path_still_refuses_till_cash(): void
    {
        // The admin form omits TILL_CASH, and the writer enforces it: petty cash is counter-only.
        $this->expectException(RuntimeException::class);
        (new RecordOverhead)->handle($this->org, $this->category(), 5000, ExpensePaidFrom::TILL_CASH, $this->owner(), []);
    }

    public function test_a_cash_expense_appears_in_the_financial_report_expenses(): void
    {
        (new RecordOverhead)->handle($this->org, $this->category(), 5000, ExpensePaidFrom::CASH, $this->owner(), [
            'location_id' => $this->location->id, 'incurred_on' => now(),
        ]);

        $gastos = (new FinancialReport($this->org->id, [$this->location->id], Period::thisMonth()))->primary()->totals['gastos'];
        $this->assertSame(5000, $gastos);
    }
}
