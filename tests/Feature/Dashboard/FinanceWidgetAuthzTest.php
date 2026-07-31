<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DispensationStatus;
use App\Enums\Role;
use App\Filament\Widgets\IncomeByPeriodChart;
use App\Filament\Widgets\IncomeVsExpensesChart;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\DashboardCharts;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 33 — audit A1. Finance authorisation lives at the widget (canView) AND the
 * data layer (canSeeFinance) — never only in a blade @if. A STAFF panel user must not
 * be able to read org-wide income/expense figures the role is meant to never see.
 */
class FinanceWidgetAuthzTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function seedIncome(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => 3500, 'cash_cents' => 3500, 'wallet_cents' => 0,
            'dispensed_at' => now(),
        ]);
        DispensationLine::factory()->create([
            'dispensation_id' => $dispensation->id, 'genetic_id' => $this->genetic->id,
            'grams_cg' => 350, 'line_total_cents' => 3500, 'genetic_name_snapshot' => 'Amnesia',
        ]);
    }

    public function test_staff_cannot_view_the_finance_widgets_but_the_owner_can(): void
    {
        $this->actingAs($this->user(Role::STAFF));
        $this->assertFalse(IncomeVsExpensesChart::canView());
        $this->assertFalse(IncomeByPeriodChart::canView());

        $this->actingAs($this->user(Role::OWNER));
        $this->assertTrue(IncomeVsExpensesChart::canView());
        $this->assertTrue(IncomeByPeriodChart::canView());
    }

    public function test_the_income_data_layer_returns_empty_for_a_non_finance_actor(): void
    {
        $this->seedIncome();
        $charts = DashboardCharts::for($this->user(Role::STAFF), Period::today());

        // Empty regardless of what calls it — not merely hidden by a blade @if.
        $this->assertSame([], $charts->incomeByPeriod()['aportaciones']);
        $this->assertSame([], $charts->incomeVsExpenses()['ingresos']);
    }

    public function test_the_income_data_layer_returns_real_figures_for_a_finance_actor(): void
    {
        $this->seedIncome();
        $charts = DashboardCharts::for($this->user(Role::OWNER), Period::today());

        $this->assertGreaterThan(0, array_sum($charts->incomeByPeriod()['aportaciones']));
        $this->assertGreaterThan(0, array_sum($charts->incomeVsExpenses()['ingresos']));
    }

    public function test_dispensed_by_genetic_zeroes_the_value_for_a_non_finance_actor(): void
    {
        $this->seedIncome();

        // Operational grams stay visible to STAFF; the € value is zeroed (audit A1) so the
        // chart's "Importe" mode can't leak per-genetic revenue.
        $staff = DashboardCharts::for($this->user(Role::STAFF), Period::today())->dispensedByGenetic();
        $this->assertSame(0, $staff[0]['total_cents']);
        $this->assertSame(350, $staff[0]['grams_cg']);

        $owner = DashboardCharts::for($this->user(Role::OWNER), Period::today())->dispensedByGenetic();
        $this->assertSame(3500, $owner[0]['total_cents']);
    }
}
