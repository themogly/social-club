<?php

namespace Tests\Feature\Reports;

use App\Actions\Bar\CommitOrder;
use App\Actions\Till\OpenTill;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Reports\BarSalesReport;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fixtures/seeds MUST go through the domain action that owns the write (see CLAUDE.md), so a green
 * report can never certify a shape production never produces. The Bar sales report read €0,00 against
 * 103 seeded units because DemoDataSeeder hand-built the JSON `items` as {name, qty, price_cents}
 * while the real writer (CommitOrder) writes {article_id, unit_price_cents, line_total_cents} — and
 * BarSalesReport sums `line_total_cents`, grouped by `article_id`. This is the standing guard: an
 * order created the way the seeder now creates it (via CommitOrder) carries the real shape AND
 * reconciles with FinancialReport's Barra column — not zero.
 */
class SeededOrderShapeReconcilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
    }

    public function test_an_order_written_the_seeder_way_carries_the_real_shape_and_reconciles(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $operator = User::factory()->create();
        $article = Article::factory()->create([
            'organisation_id' => $org->id, 'location_id' => $location->id,
            'price_cents' => 250, 'stock' => 20, 'active' => true,
        ]);

        $till = (new OpenTill)->handle($location, 'POS-1', 10000);

        // Exactly how DemoDataSeeder::seedOrders now creates orders — article_id + qty, nothing hand-built.
        $order = (new CommitOrder)->handle($location, [
            ['article_id' => $article->id, 'qty' => 3],
        ], [
            'operator_id' => $operator->id,
            'till_session_id' => $till->id,
            'idempotency_key' => (string) Str::ulid(),
        ]);

        // The stored snapshot carries the keys BarSalesReport reads — never the seeder's old {name, qty, price_cents}.
        $item = $order->items[0];
        $this->assertArrayHasKey('article_id', $item);
        $this->assertArrayHasKey('unit_price_cents', $item);
        $this->assertArrayHasKey('line_total_cents', $item);
        $this->assertSame($article->id, $item['article_id']);
        $this->assertSame(750, $item['line_total_cents']);

        // The itemised report shows real revenue (not €0,00) and reconciles with the Barra aggregate.
        $barSales = new BarSalesReport($org->id, [$location->id], Period::thisMonth());
        $barra = (new FinancialReport($org->id, [$location->id], Period::thisMonth()))->primary()->totals['barra'];

        $this->assertSame(750, $barSales->primary()->totals['importe']);
        $this->assertSame($barra, $barSales->primary()->totals['importe']);
        $this->assertGreaterThan(0, $barra);
    }
}
