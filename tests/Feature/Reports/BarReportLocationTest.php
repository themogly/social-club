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
 * Prompt 69 — articles are per-location, so at an All-locations scope two same-named articles (each a
 * distinct id at its own sede) rendered as indistinguishable duplicate rows. Grouping by article_id was
 * always correct; this surfaces the SEDE so the rows are readable, without moving the period total.
 */
class BarReportLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
    }

    /** @param list<array<string,mixed>> $items */
    private function order(Location $location, array $items, int $total): void
    {
        Order::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $location->id, 'status' => OrderStatus::COMPLETED,
            'items' => $items, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0,
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    public function test_multi_location_rows_carry_a_sede_column_and_the_total_holds(): void
    {
        // "Agua" at each sede — distinct article ids, same name.
        $this->order($this->a, [['article_id' => 'agua-a', 'name' => 'Agua', 'qty' => 2, 'unit_price_cents' => 150, 'line_total_cents' => 300]], 300);
        $this->order($this->b, [['article_id' => 'agua-b', 'name' => 'Agua', 'qty' => 1, 'unit_price_cents' => 150, 'line_total_cents' => 150]], 150);

        $table = (new BarSalesReport($this->org->id, [$this->a->id, $this->b->id], Period::thisMonth()))->primary();

        $this->assertContains('sede', array_map(fn ($c): string => $c->key, $table->columns)); // sede column shown
        $this->assertCount(2, $table->rows);                                                    // two distinct rows
        $this->assertEqualsCanonicalizing(['Sede Centro', 'Sede Norte'], array_column($table->rows, 'sede'));
        $this->assertSame(450, $table->totals['importe']);                                      // total unchanged
    }

    public function test_single_location_scope_has_no_sede_column(): void
    {
        $this->order($this->a, [['article_id' => 'agua-a', 'name' => 'Agua', 'qty' => 2, 'unit_price_cents' => 150, 'line_total_cents' => 300]], 300);

        $table = (new BarSalesReport($this->org->id, [$this->a->id], Period::thisMonth()))->primary();

        $this->assertNotContains('sede', array_map(fn ($c): string => $c->key, $table->columns)); // no redundant column
        $this->assertSame(300, $table->totals['importe']);
    }

    public function test_a_manual_off_catalogue_line_still_groups_by_name(): void
    {
        $this->order($this->a, [['article_id' => null, 'name' => 'Donativo', 'qty' => 1, 'unit_price_cents' => 500, 'line_total_cents' => 500]], 500);
        $this->order($this->b, [['article_id' => null, 'name' => 'Donativo', 'qty' => 1, 'unit_price_cents' => 500, 'line_total_cents' => 500]], 500);

        $table = (new BarSalesReport($this->org->id, [$this->a->id, $this->b->id], Period::thisMonth()))->primary();

        // Manual lines have no location-bound article, so they merge by name across sedes into one row.
        $donativo = array_values(array_filter($table->rows, fn (array $r): bool => $r['articulo'] === 'Donativo'));
        $this->assertCount(1, $donativo);
        $this->assertSame(1000, $donativo[0]['importe']);
        $this->assertSame('—', $donativo[0]['sede']);
    }

    public function test_the_total_reconciles_with_financial_report_barra_across_locations(): void
    {
        $this->order($this->a, [['article_id' => 'agua-a', 'name' => 'Agua', 'qty' => 2, 'unit_price_cents' => 150, 'line_total_cents' => 300]], 300);
        $this->order($this->b, [['article_id' => 'agua-b', 'name' => 'Agua', 'qty' => 4, 'unit_price_cents' => 150, 'line_total_cents' => 600]], 600);

        $itemised = (new BarSalesReport($this->org->id, [$this->a->id, $this->b->id], Period::thisMonth()))->primary()->totals['importe'];
        $barra = (new FinancialReport($this->org->id, [$this->a->id, $this->b->id], Period::thisMonth()))->primary()->totals['barra'];

        $this->assertSame(900, $barra);
        $this->assertSame($barra, $itemised); // same money, still reconciles across sedes
    }
}
