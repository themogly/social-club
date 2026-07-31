<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\Reports\BarSalesReportPage;
use App\Filament\Pages\Reports\FinancialReportPage;
use Tests\TestCase;

/**
 * Batch 2·1 — the browser <title> of every Informes page must be its (translated) navigation label,
 * not Filament's class-derived default. The Bar sales report tab read "Bar Sales Report Page" because
 * BasePage::getTitle() headlines the class name and no report overrode it; the fix lives on the shared
 * ReportPage base so it covers all of them, so this guards two.
 */
class ReportPageTitleTest extends TestCase
{
    public function test_bar_sales_report_tab_title_is_the_nav_label_not_the_class_name(): void
    {
        app()->setLocale('es');

        $this->assertSame(__('Barra y tienda'), (new BarSalesReportPage)->getTitle()); // renamed in prompt 68
        $this->assertNotSame('Bar Sales Report Page', (new BarSalesReportPage)->getTitle());
    }

    public function test_the_base_fix_covers_sibling_report_pages_too(): void
    {
        $this->assertSame(FinancialReportPage::getNavigationLabel(), (new FinancialReportPage)->getTitle());
    }
}
