<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expenses\ApproveExpense;
use App\Actions\Expenses\RecordOverhead;
use App\Actions\Expenses\RecordTillExpense;
use App\Actions\Purchases\RecordPurchase;
use App\Actions\Till\OpenTill;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\TillSummary;
use App\Support\ZReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExpensesTest extends TestCase
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

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function category(ExpenseKind $kind = ExpenseKind::OVERHEAD): ExpenseCategory
    {
        return ExpenseCategory::factory()->create([
            'organisation_id' => $this->org->id, 'default_kind' => $kind, 'active' => true,
        ]);
    }

    public function test_till_petty_cash_reduces_expected_cash_and_shows_in_the_z_report(): void
    {
        $staff = $this->user(Role::STAFF);
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000); // €100 float
        $category = $this->category(ExpenseKind::TILL);

        (new RecordTillExpense)->handle($till, $category, 2500, $staff, ['note' => 'Bolsas y guantes']);

        $this->assertSame(7500, TillSummary::expectedCents($till)); // 10000 − 2500
        $report = ZReport::for($till);
        $this->assertSame(-2500, $report['petty_cash']);            // signed, out of the drawer
    }

    public function test_an_overhead_never_touches_a_till_session_but_still_records_as_an_outgoing(): void
    {
        $owner = $this->user(Role::OWNER);
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $category = $this->category(ExpenseKind::OVERHEAD);

        $overhead = (new RecordOverhead)->handle($this->org, $category, 45000, ExpensePaidFrom::BANK, $owner, [
            'location_id' => $this->location->id, 'note' => 'Alquiler julio',
        ]);

        // The one that gets wired wrong: it must NOT change the drawer.
        $this->assertSame(10000, TillSummary::expectedCents($till));
        $this->assertNull($overhead->till_session_id);
        $this->assertSame(ExpenseKind::OVERHEAD, $overhead->kind);

        // …but it IS a period outgoing and lands in the category breakdown.
        $outgoings = Expense::query()->withoutGlobalScopes()->concrete()->sum('amount_cents');
        $this->assertSame(45000, (int) $outgoings);
        $byCategory = Expense::query()->withoutGlobalScopes()->concrete()
            ->where('category_id', $category->id)->sum('amount_cents');
        $this->assertSame(45000, (int) $byCategory);
    }

    public function test_above_threshold_petty_cash_needs_approval_and_below_does_not(): void
    {
        $staff = $this->user(Role::STAFF);          // no expenses.approve
        $manager = $this->user(Role::MANAGER);      // has expenses.approve
        $till = (new OpenTill)->handle($this->location, 'POS-1', 100000);
        $category = $this->category(ExpenseKind::TILL);

        // Threshold default is €100 (10000c).
        $below = (new RecordTillExpense)->handle($till, $category, 5000, $staff);
        $this->assertFalse($below->requiresApproval());

        $above = (new RecordTillExpense)->handle($till, $category, 15000, $staff);
        $this->assertTrue($above->requiresApproval());

        // Staff cannot approve; a manager can, and it becomes a recorded approval.
        try {
            (new ApproveExpense)->handle($above, $staff);
            $this->fail('Staff without expenses.approve should not be able to approve.');
        } catch (AuthorizationException) {
            // expected
        }

        (new ApproveExpense)->handle($above, $manager);
        $this->assertFalse($above->fresh()->requiresApproval());
        $this->assertNotNull($above->fresh()->approved_at);
    }

    public function test_the_recurring_job_creates_one_expense_per_schedule_per_period_across_two_runs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $owner = $this->user(Role::OWNER);
        $category = $this->category(ExpenseKind::OVERHEAD);

        // A monthly rent TEMPLATE (recurrence set → excluded from outgoings until materialised).
        (new RecordOverhead)->handle($this->org, $category, 60000, ExpensePaidFrom::BANK, $owner, [
            'recurrence' => ['frequency' => 'MONTHLY'], 'note' => 'Alquiler mensual',
        ]);

        Artisan::call('expenses:materialise-recurring');
        Artisan::call('expenses:materialise-recurring'); // retry — must not double-charge

        $concrete = Expense::query()->withoutGlobalScopes()->concrete()->where('category_id', $category->id)->get();
        $this->assertCount(1, $concrete);
        $this->assertSame(60000, $concrete->first()->amount_cents->cents);
        $this->assertSame('2026-07-01', $concrete->first()->incurred_on->toDateString());
        $this->assertDatabaseCount('recurring_expense_runs', 1);
    }

    public function test_a_purchase_linked_to_a_batch_intake_carries_cost_per_gram_into_valuation(): void
    {
        $supplier = Supplier::factory()->create(['organisation_id' => $this->org->id]);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 10000, 'cost_per_gram_cents' => 0,
        ]);

        // €500,00 for 100 g (10 000 cg) → €5,00/g = 500 cents/g.
        (new RecordPurchase)->handle($supplier, 50000, [
            'location_id' => $this->location->id, 'batch_id' => $batch->id, 'intake_grams_cg' => 10000,
        ]);

        $this->assertSame(500, $batch->fresh()->cost_per_gram_cents);
    }

    public function test_recording_an_overhead_is_refused_to_a_manager_and_to_staff(): void
    {
        $category = $this->category(ExpenseKind::OVERHEAD);

        foreach ([Role::MANAGER, Role::STAFF] as $role) {
            $actor = $this->user($role);
            try {
                (new RecordOverhead)->handle($this->org, $category, 45000, ExpensePaidFrom::BANK, $actor);
                $this->fail("{$role->value} must not be able to record an overhead.");
            } catch (AuthorizationException) {
                // expected — expenses.overheads is treasurer-only
            }
            $this->assertFalse($actor->can('expenses.overheads'));
        }
    }
}
