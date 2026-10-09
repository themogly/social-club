<?php

namespace Tests\Feature\Reports;

use App\Enums\AlertType;
use App\Enums\DispensationStatus;
use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\LossesReportPage;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\CurrentAlerts;
use App\ViewModels\Reports\LossesReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 377 — the per-person alert's link lost the sede: it opened Pérdidas on all sedes, where the person's takings at both
 * sedes halve the share (6 % at Centro reads 3 % overall), so the owner tapped «over the threshold» and saw someone under it.
 */
class PeopleAlertSedeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid']);
        $this->owner = $this->person(Role::OWNER, 'Olga Dueña');
        $this->ana = $this->person(Role::STAFF, 'Ana Barra');
        $this->actingAs($this->owner);
    }

    private function person(Role $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->centro->id, $this->norte->id]);

        return $user;
    }

    private function sale(Location $at, int $total, ?int $original = null): void
    {
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $at->id, 'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
            'operator_id' => $this->ana->id, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0, 'original_total_cents' => $original,
            'price_override_by' => $original !== null ? $this->ana->id : null, 'price_override_reason' => $original !== null ? 'Socio habitual' : null,
            'status' => DispensationStatus::COMPLETED, 'dispensed_at' => now(),
        ]);
        DispensationLine::factory()->create(['dispensation_id' => $d->id, 'grams_cg' => 100, 'charged_cg' => 100, 'price_per_gram_cents' => 1000, 'discount_cents' => 0, 'line_total_cents' => $total]);
    }

    /** Ana at Centro: €60.00 given away in a price adjustment (over the €50 limit). At Norte: another €100.00, cleanly. */
    private function week(): void
    {
        $this->sale($this->centro, 4000, original: 10000);
        $this->sale($this->centro, 600);
        $this->sale($this->norte, 10000);
    }

    // --- 1. The link carries the sede ----------------------------------------------------------------------------------------

    public function test_the_link_opens_the_report_on_the_sede_where_the_person_is_over_and_the_share_matches(): void
    {
        $this->week();
        app(ActiveScope::class)->setLocation(null); // the owner on «Todas las sedes»

        $expected = LossesReportPage::getUrl(['period' => 'last7', 'sort' => 'mostrador', 'scope' => $this->centro->id, 'person' => $this->ana->id]);
        $html = Livewire::test(Dashboard::class)->html();
        $this->assertStringContainsString('href="'.e($expected).'"', $html);

        $alert = collect(CurrentAlerts::for($this->org))->firstWhere('type', AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD);
        $this->assertSame($this->centro->id, $alert['location_id']);
        $this->assertSame($expected, $alert['detail']['url']);

        // Following it: Por persona at Centro over the 7 days shows what Ana gave at the counter, the alert's figure.
        $row = collect((new LossesReport($this->org->id, [$this->centro->id], LossesReport::lastSevenDays($this->centro)))->byPerson())->firstWhere('operator_id', $this->ana->id);
        $this->assertSame((int) $alert['detail']['cents'], $row['mostrador']);

        Livewire::withQueryParams(['period' => 'last7', 'sort' => 'mostrador', 'scope' => $this->centro->id, 'person' => $this->ana->id])
            ->test(LossesReportPage::class)->assertSet('scope', $this->centro->id)->assertSet('person', $this->ana->id);
    }

    // --- 2. The line names the sede when the view is not already that sede -------------------------------------------------

    public function test_the_line_names_the_sede_on_all_sedes_and_not_when_scoped_to_it(): void
    {
        $this->week();

        app(ActiveScope::class)->setLocation(null);
        Livewire::test(Dashboard::class)->assertSee('Sede Centro: '.trans_choice(':count persona con muchos descuentos esta semana|:count personas con muchos descuentos esta semana', 1, ['count' => 1]));

        app(ActiveScope::class)->setLocation($this->centro->id);
        $scoped = Livewire::test(Dashboard::class);
        $scoped->assertSee(trans_choice(':count persona con muchos descuentos esta semana|:count personas con muchos descuentos esta semana', 1, ['count' => 1]));
        $scoped->assertDontSee('Sede Centro: 1 persona');
    }
}
