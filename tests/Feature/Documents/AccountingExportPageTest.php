<?php

namespace Tests\Feature\Documents;

use App\Enums\DispensationStatus;
use App\Enums\Role;
use App\Filament\Pages\ExportacionContable;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class AccountingExportPageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
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

    private function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_the_csv_download_reconciles_with_the_financial_report(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => 8500, 'cash_cents' => 8500, 'wallet_cents' => 0,
            'dispensed_at' => now(),
        ]);

        // The accounting export is a Spanish-format document (decimal comma, no symbol).
        app()->setLocale('es');
        $page = new ExportacionContable;
        $page->period = 'month';
        $csv = $this->streamed($page->exportCsv());

        // The FinancialReport income the accountant sees on screen…
        $report = new FinancialReport($this->org->id, null, Period::thisMonth());
        $reportIncome = 0;
        foreach ($report->tables() as $table) {
            if ($table->key === 'methods') {
                $reportIncome = (int) ($table->totals['importe'] ?? 0);
            }
        }

        $this->assertSame(8500, $reportIncome);
        // …appears, to the cent, in the downloaded CSV (derived, never re-computed) — the
        // AccountingExport writes bare, summable numbers (Spanish decimal comma, no symbol).
        $this->assertStringContainsString('85,00', $csv);
    }

    public function test_the_page_is_forbidden_to_staff(): void
    {
        $staff = $this->user(Role::STAFF);
        $this->assertFalse($staff->can('reports.export'));

        $this->actingAs($staff);
        $this->assertFalse(ExportacionContable::canAccess());
        $this->get(ExportacionContable::getUrl())->assertForbidden();
    }
}
