<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\Reports\BarSalesReportPage;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Reports\BarSalesReport;
use App\ViewModels\Reports\FinancialReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 68 — the non-cannabis income stream is renamed "Barra y tienda" (bar + shop) in the reporting
 * layer, because it covers merch, not only the bar. The counter surface (BarPos / the "Barra" switcher /
 * the route) is deliberately untouched, and — critically — NO figure moves: the internal `barra` totals
 * key is unchanged, only the display labels.
 */
class BarSalesRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_and_nav_use_the_new_bar_and_shop_name(): void
    {
        app()->setLocale('es');
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        $this->assertSame('Barra y tienda por artículo', (new BarSalesReport($org->id, null, Period::thisMonth()))->title());
        $this->assertSame('Barra y tienda', BarSalesReportPage::getNavigationLabel());
    }

    public function test_no_figure_moves_the_internal_totals_key_is_unchanged(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        // The consumers (charts, reconciliation) read the `barra` KEY, which the rename never touched —
        // so a rename can't silently drop or move the figure.
        $totals = (new FinancialReport($org->id, null, Period::thisMonth()))->primary()->totals;
        $this->assertArrayHasKey('barra', $totals);
    }

    public function test_the_old_user_facing_bar_sales_term_is_gone_from_the_lang_files(): void
    {
        foreach (['es', 'en'] as $locale) {
            $json = (string) file_get_contents(lang_path("{$locale}.json"));
            $this->assertStringNotContainsString('Ventas de barra', $json, "[{$locale}] still carries the old 'Ventas de barra' term.");
        }
    }
}
