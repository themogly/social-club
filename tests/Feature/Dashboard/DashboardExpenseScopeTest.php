<?php

namespace Tests\Feature\Dashboard;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\StockCeiling;
use App\ViewModels\DashboardCharts;
use App\ViewModels\Reports\FinancialReport;
use App\ViewModels\Reports\StockReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 107 — the dashboard's superávit could not see org-level overheads (rent/utilities live at
 * location_id = null, which `whereIn(location_id)` can never match) and its raw aggregates counted
 * soft-deleted rows. The financial report was already right; the dashboard now shares its one expense rule
 * (Expense::concreteForPeriod) and re-applies the soft-delete filter to the four aggregating raw queries.
 */
class DashboardExpenseScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00')); // mid-month, inside "this month"
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function expense(int $cents, ?string $locationId): Expense
    {
        return Expense::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $locationId,
            'amount_cents' => $cents,
            'incurred_on' => now()->toDateString(),
            'recurrence' => null, // concrete
        ]);
    }

    private function dashboardOutgoings(?array $locationIds): int
    {
        $period = Period::thisMonth();

        return (int) array_sum((new DashboardCharts($this->org->id, $locationIds, $period))->incomeVsExpenses($period)['gastos']);
    }

    private function reportOutgoings(?array $locationIds): int
    {
        $period = Period::thisMonth();
        $report = new FinancialReport($this->org->id, $locationIds, $period);
        $report->primary();

        return (int) $report->primary()->totals['gastos'];
    }

    public function test_the_all_locations_dashboard_outgoings_match_the_financial_report(): void
    {
        $this->expense(21510, $this->location->id);  // a sede expense
        $this->expense(120000, null);                // an ORG-LEVEL overhead (rent) — the reproduction

        $this->assertSame($this->reportOutgoings(null), $this->dashboardOutgoings(null));
        $this->assertSame(21510 + 120000, $this->dashboardOutgoings(null)); // the overhead IS counted now
    }

    public function test_a_location_scoped_view_does_not_pick_up_org_level_overheads(): void
    {
        $this->expense(21510, $this->location->id);
        $this->expense(120000, null); // org-level rent

        // A manager at one sede sees only that sede's outgoings — the fold-in is the all-locations view only.
        $this->assertSame(21510, $this->dashboardOutgoings([$this->location->id]));
    }

    public function test_soft_deleting_an_expense_removes_it_from_both_dashboard_and_report(): void
    {
        $keep = $this->expense(10000, $this->location->id);
        $drop = $this->expense(21510, $this->location->id);

        $this->assertSame(31510, $this->dashboardOutgoings(null));

        $drop->delete();

        $this->assertSame(10000, $this->dashboardOutgoings(null));
        $this->assertSame(10000, $this->reportOutgoings(null));
    }

    public function test_soft_deleting_an_open_batch_removes_it_from_the_stock_chart_and_the_ceiling(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 5000,
        ]);

        $this->assertSame(5000, StockCeiling::forLocation($this->location)['on_site_cg']);
        $this->assertNotEmpty((new DashboardCharts($this->org->id, null, Period::today()))->stockLevelsByGenetic()['grams']);

        $batch->delete();

        $this->assertSame(0, StockCeiling::forLocation($this->location)['on_site_cg']);
        $this->assertEmpty((new DashboardCharts($this->org->id, null, Period::today()))->stockLevelsByGenetic()['grams']);
    }

    public function test_label_lookups_still_resolve_names_for_soft_deleted_parents(): void
    {
        // The aggregate-vs-lookup distinction: the stock aggregate excludes deleted BATCHES, but the
        // genetic-NAME lookup must still resolve a soft-deleted parent, or an old report renders blanks.
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Fantasma OG']);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 5000,
        ]);
        $genetic->delete(); // soft-delete the PARENT, keep the batch

        $report = new StockReport($this->org->id, null, Period::today());
        $names = collect($report->tables()[0]->rows)->pluck('genetica')->all();
        $this->assertContains('Fantasma OG', $names);
    }

    public function test_soft_deleted_members_do_not_appear_in_the_new_joiners_series(): void
    {
        Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => now()->subDay()]);
        $gone = Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => now()->subDay()]);

        $charts = fn () => array_sum((new DashboardCharts($this->org->id, null, Period::today()))->newMembersSeries());
        $this->assertSame(2, $charts());

        $gone->delete();
        $this->assertSame(1, $charts());
    }
}
