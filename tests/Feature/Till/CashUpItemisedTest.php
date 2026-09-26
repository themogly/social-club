<?php

namespace Tests\Feature\Till;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Filament\Resources\TillSessions\Pages\ViewTillSession;
use App\Livewire\Counter\TillSession;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Period;
use App\Support\TillSummary;
use App\ViewModels\Reports\TillReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 265 §1 — the cash-up says what each petty-cash expense was FOR, not only "Caja chica €19,70".
 *
 * The expenses were always stored (category, note, amount, who). They never appeared where it matters: on the till
 * during the shift, on the arqueo after closing (where a short drawer gets explained), in the admin session view and
 * in the till report. One source now — `TillSummary::breakdown()['petty_cash_items']` — and every view reads it.
 */
class CashUpItemisedTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $manager;

    private ExpenseCategory $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $this->manager = User::factory()->create(['name' => 'Marta Encargada']);
        $this->manager->assignRole(Role::MANAGER->value);
        $this->manager->locations()->sync([$this->location->id]);
        $this->actingAs($this->manager);
        CounterOperator::set($this->manager);

        $this->stock = ExpenseCategory::create(['organisation_id' => $this->org->id, 'name' => 'Stock', 'active' => true]);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    /** Two till expenses through the real screen, then (optionally) the blind close. */
    private function twoExpenses(): Testable
    {
        $till = Livewire::test(TillSession::class);
        foreach ([['12,50', 'Leche y café'], ['7,20', 'Bolsas de basura']] as [$amount, $note]) {
            $till->set('expenseCategoryId', $this->stock->id)->set('expenseAmount', $amount)->set('expenseNote', $note)
                ->call('recordExpense')->assertSet('flashType', 'success');
        }

        return $till;
    }

    public function test_the_breakdown_carries_the_items_and_they_sum_to_the_petty_cash_total(): void
    {
        $this->twoExpenses();
        $b = TillSummary::breakdown(TillSessionModel::query()->withoutGlobalScopes()->sole());

        $this->assertSame(-1970, $b['petty_cash']);
        $this->assertSame(['Bolsas de basura', 'Leche y café'], collect($b['petty_cash_items'])->pluck('note')->sort()->values()->all());
        $this->assertSame(1970, (int) collect($b['petty_cash_items'])->sum('amount_cents'));
        $this->assertSame('Stock', $b['petty_cash_items'][0]['category']);
        $this->assertSame('Marta Encargada', $b['petty_cash_items'][0]['recorded_by']);
    }

    public function test_the_till_screen_itemises_petty_cash_during_the_shift(): void
    {
        $html = $this->twoExpenses()->html();

        $this->assertStringContainsString('data-petty-cash-items', $html);
        $this->assertStringContainsString('Leche y café', $html);
        $this->assertStringContainsString('Bolsas de basura', $html);
    }

    public function test_the_closed_arqueo_lists_each_expense_with_category_amount_and_recorder(): void
    {
        $till = $this->twoExpenses();

        $html = $till->call('startClose')->set('countInput', '80,30')->call('submitCount')
            ->assertSet('countSubmitted', true)
            ->html();

        foreach (['Leche y café', 'Bolsas de basura', 'Stock', 'Marta Encargada'] as $needle) {
            $this->assertStringContainsString(e($needle), $html, "the closed arqueo does not show {$needle}");
        }
        $this->assertStringContainsString(e(Money::fromCents(1250)->formatted()), $html);
        $this->assertStringContainsString(e(Money::fromCents(720)->formatted()), $html);
        $this->assertStringContainsString(e(Money::fromCents(1970)->formatted()), $html, 'the petty-cash total is not on the arqueo');
    }

    public function test_the_blind_count_still_reveals_nothing(): void
    {
        $till = $this->twoExpenses()->call('startClose');

        $expected = Money::fromCents(TillSummary::breakdown(TillSessionModel::query()->withoutGlobalScopes()->sole())['expected'])->formatted();
        $html = $till->html();

        $this->assertStringNotContainsString(e($expected), $html, 'the count step shows the expected figure');
        $this->assertStringNotContainsString('data-petty-cash-items', $html, 'the itemised list leaked into the blind count');
        $till->assertSet('expected', null);
    }

    public function test_the_admin_session_view_and_the_till_report_list_the_same_items(): void
    {
        $this->twoExpenses();
        $session = TillSessionModel::query()->withoutGlobalScopes()->sole();
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');

        Livewire::actingAs($owner)->test(ViewTillSession::class, ['record' => $session->getRouteKey()])
            ->assertSee('Leche y café')->assertSee('Bolsas de basura')->assertSee('Marta Encargada');

        $report = new TillReport($this->org->id, [$this->location->id], Period::today());
        $table = collect($report->tables())->firstWhere('key', 'petty_cash_items');
        $this->assertNotNull($table, 'the till report does not itemise petty cash');
        $this->assertSame(['Bolsas de basura', 'Leche y café'], collect($table->rows)->pluck('nota')->sort()->values()->all());
        $this->assertSame(1970, (int) collect($table->rows)->sum('importe'));
    }
}
