<?php

namespace Tests\Feature\Till;

use App\Actions\Till\OpenTill;
use App\Enums\ExpenseKind;
use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TillPettyCashTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value); // holds expenses.record + till.open
        CounterOperator::set($user); // PIN-identified operator (prompt 26 guard)

        return $user;
    }

    /**
     * The counter petty-cash affordance records a TILL expense against the open drawer
     * and — because it routes through RecordTillExpense (a PETTY_CASH movement) — drops
     * the expected drawer cash by the amount. It never writes the Expense directly.
     */
    public function test_the_counter_petty_cash_action_records_a_till_expense_and_reduces_expected_cash(): void
    {
        $this->actingAs($this->staff());
        app(ActiveScope::class)->setLocation($this->location->id);

        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000); // €100 float
        $this->assertSame(10000, TillSummary::expectedCents($session));

        $category = ExpenseCategory::factory()->create([
            'organisation_id' => $this->org->id, 'default_kind' => ExpenseKind::TILL, 'active' => true,
        ]);

        Livewire::test(TillSession::class)
            ->assertOk()
            ->assertSet('terminal', 'POS-1') // the single open session is adopted on mount
            ->set('expenseCategoryId', $category->id)
            ->set('expenseAmount', '25')     // €25,00
            ->set('expenseNote', 'Bolsas y guantes')
            ->call('recordExpense')
            ->assertSet('flashType', 'success')
            ->assertSet('expenseAmount', ''); // cleared on success

        // A TILL expense, attached to the session and stored as integer cents.
        $expense = Expense::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ExpenseKind::TILL, $expense->kind);
        $this->assertSame(2500, $expense->amount_cents->cents);
        $this->assertSame($session->id, $expense->till_session_id);

        // …and the drawer's expected cash dropped by the petty cash (€100 − €25 = €75).
        $this->assertSame(7500, TillSummary::expectedCents($session));
    }

    /**
     * Denial test: a user who may operate the till (till.open) but NOT record expenses
     * is refused by recordExpense — no expense is written and the drawer is untouched.
     */
    public function test_the_petty_cash_action_is_refused_without_the_record_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('till.open'); // can open the drawer, but has no expenses.record
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user); // pass the operator guard so the PERMISSION check is what denies

        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $category = ExpenseCategory::factory()->create([
            'organisation_id' => $this->org->id, 'default_kind' => ExpenseKind::TILL, 'active' => true,
        ]);

        Livewire::test(TillSession::class)
            ->assertSet('terminal', 'POS-1')
            ->set('expenseCategoryId', $category->id)
            ->set('expenseAmount', '25')
            ->call('recordExpense')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Expense::query()->withoutGlobalScopes()->count());
        $this->assertSame(10000, TillSummary::expectedCents($session)); // drawer untouched
    }
}
