<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Reports\BarSalesReport;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 43 — the itemised bar-sales report. Aggregates the order `items` snapshots by
 * article (units + revenue) and, critically, its grand total reconciles with the "Barra"
 * aggregate FinancialReport already shows — the same money at finer granularity.
 */
class BarSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        app()->setLocale('es');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function order(Location $location, array $items, int $total, OrderStatus $status = OrderStatus::COMPLETED): Order
    {
        return Order::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $location->id,
            'status' => $status,
            'items' => $items,
            'total_cents' => $total,
            'cash_cents' => $total,
            'wallet_cents' => 0,
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    private function report(): BarSalesReport
    {
        return new BarSalesReport($this->org->id, [$this->a->id], Period::thisMonth());
    }

    public function test_it_aggregates_units_and_revenue_per_article_sorted_by_revenue(): void
    {
        $this->order($this->a, [
            ['article_id' => 'art-beer', 'name' => 'Cerveza', 'qty' => 2, 'unit_price_cents' => 250, 'line_total_cents' => 500],
            ['article_id' => 'art-water', 'name' => 'Agua', 'qty' => 1, 'unit_price_cents' => 150, 'line_total_cents' => 150],
        ], 650);
        $this->order($this->a, [
            ['article_id' => 'art-beer', 'name' => 'Cerveza', 'qty' => 3, 'unit_price_cents' => 250, 'line_total_cents' => 750],
        ], 750);

        $rows = $this->report()->primary()->rows;

        // Cerveza aggregated across the two orders (5 units, €12,50), top by revenue.
        $this->assertSame('Cerveza', $rows[0]['articulo']);
        $this->assertSame(5, $rows[0]['unidades']);
        $this->assertSame(1250, $rows[0]['importe']);
        $this->assertSame('Agua', $rows[1]['articulo']);
        $this->assertSame(150, $rows[1]['importe']);
    }

    public function test_off_catalogue_manual_lines_are_included_so_the_total_reconciles(): void
    {
        $this->order($this->a, [
            ['article_id' => 'art-beer', 'name' => 'Cerveza', 'qty' => 1, 'unit_price_cents' => 250, 'line_total_cents' => 250],
            ['article_id' => null, 'name' => 'Donativo', 'qty' => 1, 'unit_price_cents' => 300, 'line_total_cents' => 300],
        ], 550);

        $totals = $this->report()->primary()->totals;
        $this->assertSame(550, $totals['importe']); // both the article AND the manual line counted
    }

    public function test_the_total_reconciles_with_the_financial_report_barra_column(): void
    {
        $this->order($this->a, [['article_id' => 'art-1', 'name' => 'Cerveza', 'qty' => 2, 'unit_price_cents' => 250, 'line_total_cents' => 500]], 500);
        $this->order($this->a, [['article_id' => null, 'name' => 'Propina', 'qty' => 1, 'unit_price_cents' => 200, 'line_total_cents' => 200]], 200);
        $this->order($this->a, [['article_id' => 'art-1', 'name' => 'Cerveza', 'qty' => 4, 'unit_price_cents' => 250, 'line_total_cents' => 1000]], 1000);

        $barra = (new FinancialReport($this->org->id, [$this->a->id], Period::thisMonth()))->primary()->totals['barra'];
        $itemised = $this->report()->primary()->totals['importe'];

        $this->assertSame(1700, $barra);
        $this->assertSame($barra, $itemised); // same money, finer granularity — never a drifting figure
    }

    public function test_voided_and_other_location_orders_are_excluded(): void
    {
        $this->order($this->a, [['article_id' => 'art-1', 'name' => 'Cerveza', 'qty' => 1, 'unit_price_cents' => 250, 'line_total_cents' => 250]], 250);
        $this->order($this->a, [['article_id' => 'art-1', 'name' => 'Cerveza', 'qty' => 9, 'unit_price_cents' => 250, 'line_total_cents' => 2250]], 2250, OrderStatus::VOIDED);
        $this->order($this->b, [['article_id' => 'art-1', 'name' => 'Cerveza', 'qty' => 9, 'unit_price_cents' => 250, 'line_total_cents' => 2250]], 2250);

        // Only the one COMPLETED order at location A counts.
        $this->assertSame(250, $this->report()->primary()->totals['importe']);
    }
}
