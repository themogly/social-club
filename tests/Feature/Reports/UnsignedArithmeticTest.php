<?php

namespace Tests\Feature\Reports;

use App\Enums\DispensationStatus;
use App\Enums\Role;
use App\Filament\Pages\RegistroDispensacion;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Period;
use App\ViewModels\Reports\ConsumptionReport;
use App\ViewModels\Reports\DiscountsReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Prompt 370 — HOTFIX. Sentry, 8 October, `GET /documentos/registro-dispensacion`: «SQLSTATE[22003] 1690 BIGINT UNSIGNED value
 * is out of range in (original_total_cents - total_cents)». `original_total_cents` is UNSIGNED; since 356 a price can be
 * adjusted UP, so the difference goes negative, and MySQL evaluates `unsigned − signed` as unsigned: one raised sale and the
 * whole month's register (and the consumption report) 500s. SQLite has no unsigned arithmetic, so the suite stayed green.
 *
 * Run on BOTH drivers: `phpunit.xml` (SQLite) and `phpunit.mysql.xml` (MySQL — red there before the fix, error 1690).
 */
class UnsignedArithmeticTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    /** One adjusted DOWN (€30 → €25: €5 given) and one adjusted UP (€30 → €34: €4 recovered), this month. */
    private function adjustedSales(): void
    {
        foreach ([[3000, 2500], [3000, 3400]] as [$original, $charged]) {
            Dispensation::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->sede->id,
                'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
                'operator_id' => $this->owner->id, 'price_override_by' => $this->owner->id, 'price_override_reason' => 'Ajuste de prueba',
                'total_cents' => $charged, 'cash_cents' => $charged, 'wallet_cents' => 0, 'original_total_cents' => $original,
                'status' => DispensationStatus::COMPLETED, 'dispensed_at' => now(),
            ]);
        }
    }

    /** @return array<string, string> the summary chips, label => value */
    private function chips(ConsumptionReport|DiscountsReport $report): array
    {
        return collect($report->summary())->mapWithKeys(fn (array $chip): array => [$chip['label'] => $chip['value']])->all();
    }

    // --- 1/2. The register renders with a raised price, and shows given and recovered apart ------------------------------------

    public function test_the_register_renders_with_a_raised_price_and_shows_given_and_recovered_apart(): void
    {
        $this->adjustedSales();

        $this->get(RegistroDispensacion::getUrl())
            ->assertOk()
            ->assertSee('Ajustes de precio: cedido')
            ->assertSee('Ajustes de precio: recuperado');

        $chips = $this->chips(new ConsumptionReport($this->org->id, [$this->sede->id], Period::thisMonth($this->sede)));
        $this->assertSame(Money::fromCents(500)->formatted(), $chips['Ajustes de precio: cedido']);
        $this->assertSame(Money::fromCents(400)->formatted(), $chips['Ajustes de precio: recuperado']);
    }

    // --- 4. Consumo and Descuentos y ajustes agree -------------------------------------------------------------------------------

    public function test_consumo_and_descuentos_show_the_same_given_and_recovered(): void
    {
        $this->adjustedSales();
        $period = Period::thisMonth($this->sede);

        $consumo = $this->chips(new ConsumptionReport($this->org->id, [$this->sede->id], $period));
        $descuentos = $this->chips(new DiscountsReport($this->org->id, [$this->sede->id], $period));

        foreach (['Ajustes de precio: cedido', 'Ajustes de precio: recuperado'] as $label) {
            $this->assertArrayHasKey($label, $descuentos, $label);
            $this->assertSame($consumo[$label], $descuentos[$label], $label);
        }
    }

    // --- 3. The static guard: no SQL arithmetic on an unsigned column -------------------------------------------------------------

    public function test_the_guard_flags_a_planted_unsigned_subtraction(): void
    {
        $columns = self::unsignedColumns();
        $this->assertContains('original_total_cents', $columns);
        $this->assertContains('charged_cg', $columns);

        $planted = "->sum(DB::raw('original_total_cents - total_cents'));\n->selectRaw('SUM(t.grams_cg - t.charged_cg) as loss')";
        $this->assertSame(['original_total_cents', 'charged_cg'], array_column(self::unsignedSubtractions($planted, $columns), 'column'));

        $this->assertSame([], self::unsignedSubtractions("->selectRaw('SUM(total_cents) as t, SUM(original_total_cents) as o')", $columns), 'summing is safe');
    }

    public function test_no_raw_sql_in_the_app_subtracts_with_an_unsigned_column(): void
    {
        $columns = self::unsignedColumns();
        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            foreach (self::unsignedSubtractions($file->getContents(), $columns) as $hit) {
                $found[] = $file->getRelativePathname().': '.$hit['sql'];
            }
        }

        $this->assertSame([], $found, "MySQL evaluates unsigned − signed as UNSIGNED: a negative result is error 1690.\n"
            .'Sum the columns separately and subtract in PHP (see DECISIONS.md, prompt 370).');
    }

    /** @return list<string> every column a migration declares unsigned (ids/foreign ids aside — keys are ULIDs here) */
    private static function unsignedColumns(): array
    {
        $columns = [];
        foreach (File::files(database_path('migrations')) as $file) {
            preg_match_all("/unsigned(?:Big|Medium|Small|Tiny)?Integer\\(\\s*'([a-z0-9_]+)'/", $file->getContents(), $m);
            array_push($columns, ...$m[1]);
            preg_match_all("/(?:bigInteger|integer|smallInteger|tinyInteger|mediumInteger)\\(\\s*'([a-z0-9_]+)'[^;]*->unsigned\\(\\)/", $file->getContents(), $m);
            array_push($columns, ...$m[1]);
        }

        return array_values(array_unique($columns));
    }

    /**
     * The raw SQL strings in `$code` (DB::raw, selectRaw, whereRaw, orderByRaw, havingRaw, groupByRaw) that subtract with an
     * unsigned column on either side.
     *
     * @param  list<string>  $columns
     * @return list<array{column: string, sql: string}>
     */
    private static function unsignedSubtractions(string $code, array $columns): array
    {
        preg_match_all('/(?:DB::raw|selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw)\(\s*([\'"])(.*?)(?<!\\\\)\1/s', $code, $m);
        $hits = [];
        foreach ($m[2] as $sql) {
            foreach ($columns as $column) {
                $name = '(?:`?[a-z0-9_]+`?\.)?`?'.preg_quote($column, '/').'`?';
                if (preg_match('/'.$name.'\s*-(?!-)|(?<!-)-\s*'.$name.'\b/i', $sql) === 1) {
                    $hits[] = ['column' => $column, 'sql' => $sql];
                }
            }
        }

        return $hits;
    }
}
