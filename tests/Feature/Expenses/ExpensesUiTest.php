<?php

namespace Tests\Feature\Expenses;

use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Enums\Role;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpensesUiTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_the_expense_supplier_and_purchase_indexes_load_for_an_owner(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        $this->get(route('filament.admin.resources.expenses.index'))->assertOk();
        $this->get(route('filament.admin.resources.suppliers.index'))->assertOk();
        $this->get(route('filament.admin.resources.purchases.index'))->assertOk();
    }

    /**
     * The overhead create flow is treasurer-only. A manager and a staff member (who
     * DO hold expenses.record for counter petty cash) must NOT reach it — the page is
     * gated on manageOverheads (expenses.overheads), which neither of them holds.
     */
    public function test_the_overhead_create_page_is_forbidden_to_a_manager_and_to_staff(): void
    {
        $createUrl = route('filament.admin.resources.expenses.create');

        foreach ([Role::MANAGER, Role::STAFF] as $role) {
            $actor = $this->user($role);

            $this->actingAs($actor)->get($createUrl)->assertForbidden();
            $this->assertFalse($actor->can('expenses.overheads')); // the ability the page requires
        }

        // …but the treasurer (owner) reaches it.
        $this->actingAs($this->user(Role::OWNER))->get($createUrl)->assertOk();
    }

    public function test_the_supplier_and_purchase_indexes_are_denied_to_staff(): void
    {
        // STAFF hold expenses.record (petty cash) but NOT purchases.manage.
        $this->actingAs($this->user(Role::STAFF));

        $this->get(route('filament.admin.resources.suppliers.index'))->assertForbidden();
        $this->get(route('filament.admin.resources.purchases.index'))->assertForbidden();
    }

    public function test_an_owner_records_an_overhead_through_the_resource_without_touching_a_till(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        $category = ExpenseCategory::factory()->create([
            'organisation_id' => $this->org->id, 'default_kind' => ExpenseKind::OVERHEAD, 'active' => true,
        ]);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'category_id' => $category->id,
                'paid_from' => ExpensePaidFrom::BANK->value,
                'amount_eur' => 450, // €450 entered at the edge
                'incurred_on' => '2026-07-20',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::query()->withoutGlobalScopes()->first();
        $this->assertNotNull($expense);
        $this->assertSame(45000, $expense->amount_cents->cents);   // stored as integer cents
        $this->assertSame(ExpenseKind::OVERHEAD, $expense->kind);
        $this->assertNull($expense->till_session_id);              // an overhead never touches the drawer
        // Routed through RecordOverhead (not a raw create) — proven by its audit entry.
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.overhead.recorded']);
    }

    public function test_a_purchase_created_through_the_resource_runs_record_purchase_with_the_right_owing(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        $supplier = Supplier::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'amount_eur' => 500, // €500,00
                'paid_eur' => 200,   // €200,00 → owing €300,00
                'purchased_on' => '2026-07-20',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('purchases', 1);

        $purchase = Purchase::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(50000, $purchase->amount_cents->cents);
        $this->assertSame(20000, $purchase->paid_cents->cents);
        $this->assertSame(30000, $purchase->amount_cents->cents - $purchase->paid_cents->cents); // owing
        // Routed through RecordPurchase — proven by its audit entry.
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.recorded']);
    }
}
