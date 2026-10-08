<?php

namespace Tests\Feature\Till;

use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Reports\TillReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 369 §3 — a till opened by Club Staff and closed by Club Manager at −€20.00: the report's row showed only the opener,
 * and «Descuadre por operador» put the −€20.00 against the opener. The owner asks the person who COUNTED: the row shows
 * both, and the per-operator table groups by who did the arqueo (the recommended option, recorded in DECISIONS.md).
 */
class TillReportAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_row_shows_who_opened_and_who_closed_and_the_difference_goes_to_the_counter(): void
    {
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        $staff = User::factory()->create(['name' => 'Club Staff']);
        $staff->assignRole(Role::STAFF->value);
        $manager = User::factory()->create(['name' => 'Club Manager']);
        $manager->assignRole(Role::MANAGER->value);

        $session = (new OpenTill)->handle($sede, 'POS-1', 10000, ['operator_id' => $staff->id]);
        (new CloseTill)->handle($session, 8000, $manager);

        $tables = collect((new TillReport($org->id, [$sede->id], Period::thisWeek($sede)))->tables())->keyBy('key');
        $row = $tables['sessions']->rows[0];
        $this->assertSame('Abrió: Club Staff · Cerró: Club Manager', $row['operador']);

        $byOperator = $tables['by_operator'];
        $this->assertSame(__('Descuadre por quien hizo el arqueo'), $byOperator->title);
        $this->assertSame([['operador' => 'Club Manager', 'sesiones' => 1, 'descuadre' => -2000]], $byOperator->rows);
    }
}
