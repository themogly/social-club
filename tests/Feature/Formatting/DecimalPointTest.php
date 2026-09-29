<?php

namespace Tests\Feature\Formatting;

use App\Enums\AlertType;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\AlertMessage;
use App\Support\Duration;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\Percent;
use App\Support\Period;
use App\Support\Spreadsheet\AccountingExport;
use App\Support\Weight;
use App\ViewModels\Reports\FinancialReport;
use App\ViewModels\Reports\ReportColumn;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 316 — Ben: "We wanted to use a decimal point everywhere, not commas." Every number a person reads uses a point
 * and no thousands separator, in both languages (`300.01 g`, `1234.56 €`, `€1234.56`, `7.5`), through ONE helper
 * ({@see NumberFormat}). Typing still accepts both separators. The spreadsheet exports keep the Spanish-Excel comma.
 */
class DecimalPointTest extends TestCase
{
    use RefreshDatabase;

    private static function plain(string $text): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
    }

    // --- 1. The formatters, in both languages -----------------------------------------------------------------------------

    public function test_the_formatters_use_a_point_and_no_grouping_in_both_languages(): void
    {
        foreach (['es', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('300.01 g', Weight::fromCentigrams(30001)->formatted(), $locale);
            $this->assertSame('7.5', Duration::decimalHours(450), $locale);
            $this->assertSame('12.50 %', Percent::formatted(12.5), $locale);
            $this->assertSame('1234.56', NumberFormat::decimal(1234.56, 2), $locale);
        }

        app()->setLocale('es');
        $this->assertSame('1234.56 €', self::plain(Money::fromCents(123456)->formatted()));
        app()->setLocale('en');
        $this->assertSame('€1234.56', self::plain(Money::fromCents(123456)->formatted()));
    }

    public function test_report_columns_display_with_a_point(): void
    {
        app()->setLocale('es');
        $this->assertSame('1234.56 €', self::plain((new ReportColumn('m', 'M', ReportColumn::MONEY))->display(123456)));
        $this->assertSame('12.50 g', (new ReportColumn('w', 'W', ReportColumn::WEIGHT))->display(1250));
        $this->assertSame('12345', (new ReportColumn('n', 'N', ReportColumn::NUMBER))->display(12345));
    }

    // --- 2. Pages and messages in Spanish --------------------------------------------------------------------------------

    public function test_the_batch_list_and_an_alert_read_with_a_point_in_spanish(): void
    {
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($sede->id);
        $owner = User::factory()->create(['locale' => 'es']);
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$sede->id]);
        $this->actingAs($owner);
        $genetic = Genetic::factory()->create(['organisation_id' => $org->id, 'name' => 'Amnesia']);
        Batch::factory()->create(['organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $sede->id, 'remaining_cg' => 30001, 'price_per_gram_cents' => 1000]);

        Livewire::test(ListBatches::class)->assertSee('300.01 g')->assertDontSee('300,01')->assertDontSee('10,00');

        $state = (new OwnerAlertState(['type' => AlertType::RESTOCK_FROM_STORE, 'subject' => 'genetic:x', 'detail' => [
            'name' => 'Amnesia', 'unit' => false, 'on_hand' => 3800, 'days' => 2.4, 'out' => false,
            'store' => ['quantity' => 85000, 'names' => ['Almacén'], 'location_id' => $sede->id],
        ]]))->setRelation('location', $sede);
        $text = AlertMessage::text(new Collection([$state]));
        $this->assertStringContainsString('38.00 g', $text);
        $this->assertStringContainsString('850.00 g', $text);
        $this->assertDoesNotMatchRegularExpression('/\d,\d/', $text);
    }

    // --- 3. The structural guard -------------------------------------------------------------------------------------------

    /** @return list<string> "file:line" of every number_format() that writes a literal ',' decimal outside the CSV rule */
    private static function commaDecimals(array $sources): array
    {
        $found = [];
        foreach ($sources as $path => $source) {
            if (str_ends_with($path, 'Spreadsheet/ReportExport.php')) {
                continue; // the ONE spreadsheet rule (Spanish Excel): localeCsvFormat()
            }
            foreach (explode("\n", $source) as $i => $line) {
                if (preg_match("/number_format\\([^;]*?,\\s*[^,]+?,\\s*','/", $line)) {
                    $found[] = $path.':'.($i + 1);
                }
            }
        }

        return $found;
    }

    public function test_no_number_format_writes_a_comma_decimal_outside_the_spreadsheet_rule(): void
    {
        $sources = [];
        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $sources[$file->getRelativePathname()] = $file->getContents();
            }
        }
        $this->assertSame([], self::commaDecimals($sources));

        // The guard works: a planted violation is caught.
        $this->assertSame(['planted.php:1'], self::commaDecimals(['planted.php' => "echo number_format(\$x, 2, ',', '');"]));
    }

    public function test_no_translated_copy_writes_a_number_with_a_comma_decimal(): void
    {
        $this->assertSame([], array_values(array_filter(array_keys(json_decode(File::get(lang_path('es.json')), true)),
            fn (string $key): bool => preg_match('/\d,\d/', $key) === 1)));
    }

    // --- 4. The keypad; typing still accepts both ---------------------------------------------------------------------------

    public function test_the_dispensary_keypad_decimal_key_is_a_point_and_typing_accepts_both(): void
    {
        $pad = File::get(resource_path('views/livewire/counter/dispensary-pos.blade.php'));
        $this->assertStringContainsString("@click=\"push('.')\"", $pad);
        $this->assertStringNotContainsString("push(',')", $pad);

        $this->assertSame(1250, Weight::fromGrams('12,5')->centigrams);
        $this->assertSame(1250, Weight::fromGrams('12.5')->centigrams);
    }

    // --- 5. The accounting export keeps the Spanish-Excel comma (a pin) -------------------------------------------------------

    public function test_the_accounting_export_keeps_a_comma_decimal_in_spanish(): void
    {
        app()->setLocale('es');
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        $csv = (new AccountingExport(new FinancialReport($org->id, [$sede->id], Period::thisMonth())))->csv();

        $this->assertMatchesRegularExpression('/;\d+,\d{2}/', $csv);
    }
}
