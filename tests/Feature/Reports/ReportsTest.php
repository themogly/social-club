<?php

namespace Tests\Feature\Reports;

use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\ExpenseKind;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\OrderStatus;
use App\Enums\TillSessionStatus;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TillSession;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\Reports\AgmPackReport;
use App\ViewModels\Reports\AttendanceReport;
use App\ViewModels\Reports\ConsumptionReport;
use App\ViewModels\Reports\FinancialReport;
use App\ViewModels\Reports\MembersReport;
use App\ViewModels\Reports\ReportTable;
use App\ViewModels\Reports\StockReport;
use App\ViewModels\Reports\TillReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        app()->setLocale('es');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function dispense(Location $location, int $total, int $gramsCg): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => $total, 'cash_cents' => $total,
            'wallet_cents' => 0, 'dispensed_at' => now(),
        ]);
        DispensationLine::factory()->create([
            'dispensation_id' => $d->id, 'genetic_id' => $this->genetic->id, 'grams_cg' => $gramsCg,
        ]);
    }

    private function table(object $report, string $key): ReportTable
    {
        foreach ($report->tables() as $t) {
            if ($t->key === $key) {
                return $t;
            }
        }
        $this->fail("No table [{$key}] in report.");
    }

    public function test_consumption_grams_total_equals_a_control_query_and_is_scoped(): void
    {
        $this->dispense($this->a, 3500, 350);
        $this->dispense($this->a, 5000, 500);
        $this->dispense($this->b, 9000, 900);

        $control = fn (array $locationIds) => (int) DispensationLine::query()->withoutGlobalScopes()
            ->whereHas('dispensation', fn ($q) => $q->whereIn('location_id', $locationIds)
                ->where('status', DispensationStatus::COMPLETED->value))
            ->sum('grams_cg');

        $reportA = new ConsumptionReport($this->org->id, [$this->a->id], Period::today());
        $this->assertSame($control([$this->a->id]), $this->table($reportA, 'genetics')->totals['grams']);
        $this->assertSame(850, $this->table($reportA, 'genetics')->totals['grams']); // A only

        $reportAll = new ConsumptionReport($this->org->id, null, Period::today());
        $this->assertSame(1750, $this->table($reportAll, 'genetics')->totals['grams']); // A + B
    }

    public function test_financial_income_total_equals_a_control_query(): void
    {
        $this->dispense($this->a, 3500, 350);
        $this->dispense($this->a, 5000, 500);

        $report = new FinancialReport($this->org->id, [$this->a->id], Period::today());
        $importe = $this->table($report, 'methods')->totals['importe'];

        $control = (int) Dispensation::query()->withoutGlobalScopes()
            ->where('location_id', $this->a->id)->where('status', DispensationStatus::COMPLETED->value)
            ->sum('total_cents');

        $this->assertSame($control, $importe);
        $this->assertSame(8500, $importe);
    }

    public function test_the_csv_export_carries_the_same_total_as_the_report(): void
    {
        $this->dispense($this->a, 3500, 350);
        $this->dispense($this->a, 5000, 500);

        $genetics = $this->table(new ConsumptionReport($this->org->id, [$this->a->id], Period::today()), 'genetics');
        $csv = ReportExport::csv($genetics);

        // 850 cg total → 8,50 g in the Spanish locale, rendered as a bare summable number.
        $this->assertSame(850, $genetics->totals['grams']);
        $this->assertStringContainsString('8,50', $csv);
        $this->assertStringContainsString($genetics->columns[0]->label, $csv); // header present
    }

    public function test_a_brand_new_location_renders_an_empty_report_not_an_error(): void
    {
        $report = new ConsumptionReport($this->org->id, [$this->b->id], Period::today());

        $this->assertTrue($report->isEmpty());
        $this->assertFalse($this->table($report, 'genetics')->hasRows());
    }

    public function test_every_report_builds_and_exports_over_a_seeded_dataset(): void
    {
        // A dataset that touches each report's query paths (the agent's report code shipped
        // untested — this proves every table + export runs, not just Consumo/Financiero).
        $this->dispense($this->a, 3500, 350);
        $this->dispense($this->a, 5000, 500);
        Order::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->a->id,
            'status' => OrderStatus::COMPLETED, 'total_cents' => 1000, 'cash_cents' => 1000, 'wallet_cents' => 0,
        ]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $membership = Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->a->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'expires_at' => now()->addDays(10),
        ]);
        MembershipFeePayment::factory()->create([
            'membership_id' => $membership->id, 'amount_cents' => 3000,
            'method' => FeePaymentMethod::CASH, 'paid_at' => now(),
        ]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->a->id,
            'remaining_cg' => 20000, 'cost_per_gram_cents' => 400, 'status' => BatchStatus::OPEN,
        ]);
        $supplier = Supplier::factory()->create(['organisation_id' => $this->org->id]);
        Purchase::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->a->id, 'supplier_id' => $supplier->id,
            'batch_id' => $batch->id, 'amount_cents' => 80000, 'paid_cents' => 50000,
        ]);
        $category = ExpenseCategory::factory()->create(['organisation_id' => $this->org->id]);
        Expense::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->a->id, 'category_id' => $category->id,
            'amount_cents' => 12000, 'kind' => ExpenseKind::OVERHEAD, 'incurred_on' => now()->toDateString(),
        ]);
        CheckIn::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->a->id,
            'checked_in_at' => now()->subHours(2), 'checked_out_at' => now()->subHour(),
        ]);
        TillSession::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->a->id,
            'status' => TillSessionStatus::CLOSED, 'closed_at' => now(), 'variance_cents' => 250,
        ]);

        $reports = [
            new ConsumptionReport($this->org->id, null, Period::thisMonth()),
            new FinancialReport($this->org->id, null, Period::thisMonth()),
            new StockReport($this->org->id, null, Period::thisMonth()),
            new AttendanceReport($this->org->id, null, Period::thisMonth()),
            new TillReport($this->org->id, null, Period::thisMonth()),
            new MembersReport($this->org->id, null, Period::thisMonth()),
            new AgmPackReport($this->org->id, null, Period::thisMonth()),
        ];

        foreach ($reports as $report) {
            $report->summary();
            $tables = $report->tables();
            $this->assertNotEmpty($tables, get_class($report).' produced no tables');
            foreach ($tables as $t) {
                $this->assertIsString(ReportExport::csv($t)); // every table exports without error
            }
        }
    }
}
