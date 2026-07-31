<?php

namespace Tests\Feature\Localization;

use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Prompt 25 regression guard. The dashboard alerts (and reports/register) build their
 * sentences with trans_choice(), whose pluralized keys never reached the locale files,
 * so they rendered Spanish in the English default UI. These tests assert the ACTUAL
 * rendered sentence text per locale — not key existence, which is precisely what let
 * the bug survive prompt 19's parity check.
 */
class AlertLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * key, count, expected Spanish sentence, expected English sentence. Covers every
     * alert type the panel actually implements (6 of them).
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function alertMatrix(): array
    {
        return [
            'members_over_limit' => ['members_over_limit', 1, '1 socio ha superado su límite mensual', '1 member has exceeded their monthly limit'],
            'unreconciled_till' => ['unreconciled_till', 1, '1 caja abierta sin arquear', '1 till open and unreconciled'],
            'batches_expiring' => ['batches_expiring', 1, '1 lote caduca pronto', '1 batch expiring soon'],
            'stock_ceiling_exceeded' => ['stock_ceiling_exceeded', 1, '1 sede supera el techo de existencias', '1 location over the stock ceiling'],
            'memberships_expiring' => ['memberships_expiring', 1, '1 membresía por vencer', '1 membership expiring soon'],
            'pending_applications' => ['pending_applications', 1, '1 solicitud pendiente de revisión', '1 application pending review'],
        ];
    }

    #[DataProvider('alertMatrix')]
    public function test_each_alert_type_renders_its_sentence_in_the_active_locale(string $key, int $count, string $es, string $en): void
    {
        $this->assertSame($es, $this->renderAlert($key, $count, 'es'));
        $this->assertSame($en, $this->renderAlert($key, $count, 'en'));
    }

    public function test_a_pluralized_alert_uses_the_correct_grammatical_form_in_both_locales(): void
    {
        // Not just a substituted number — the noun and verb must agree with the count.
        $this->assertSame('1 socio ha superado su límite mensual', $this->renderAlert('members_over_limit', 1, 'es'));
        $this->assertSame('3 socios han superado su límite mensual', $this->renderAlert('members_over_limit', 3, 'es'));
        $this->assertSame('1 member has exceeded their monthly limit', $this->renderAlert('members_over_limit', 1, 'en'));
        $this->assertSame('3 members have exceeded their monthly limit', $this->renderAlert('members_over_limit', 3, 'en'));
    }

    public function test_the_dashboard_page_renders_a_seeded_alert_translated_end_to_end(): void
    {
        TillSession::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'status' => TillSessionStatus::OPEN,
        ]);

        // The full request path: SetLocale middleware → Filament page → decorateAlerts → blade.
        $this->visit('es')->assertOk()->assertSee('1 caja abierta sin arquear');
        $this->visit('en')->assertOk()->assertSee('1 till open and unreconciled');
    }

    public function test_a_sample_toast_from_each_area_renders_in_both_locales(): void
    {
        // A representative notification/flash from each major area. The Filament toast
        // and the counter flash are thin wrappers over __(), so verifying the copy
        // resolves per locale verifies the toast renders per locale.
        $areas = [
            'Ajustes guardados' => 'Settings saved',                 // settings
            'Ingreso registrado' => 'Deposit recorded',              // members · wallet
            'Dispensación registrada.' => 'Dispensation recorded.',  // dispensary POS
            'Caja abierta.' => 'Till open.',                         // till
        ];

        foreach ($areas as $esCopy => $enCopy) {
            app()->setLocale('es');
            $this->assertSame($esCopy, __($esCopy), "ES copy for '{$esCopy}' regressed.");
            app()->setLocale('en');
            $this->assertSame($enCopy, __($esCopy), "EN copy for '{$esCopy}' regressed.");
        }
    }

    // --- helpers --------------------------------------------------------------------

    /** Invoke the real Dashboard::decorateAlerts() for one alert tuple in a given locale. */
    private function renderAlert(string $key, int $count, string $locale): string
    {
        app()->setLocale($locale);

        $page = (new ReflectionClass(Dashboard::class))->newInstanceWithoutConstructor();
        $decorate = new ReflectionMethod(Dashboard::class, 'decorateAlerts');
        $decorate->setAccessible(true);

        /** @var list<array{message: string}> $decorated */
        $decorated = $decorate->invoke($page, [['severity' => 'warning', 'key' => $key, 'count' => $count]]);

        return $decorated[0]['message'];
    }

    private function visit(string $locale): TestResponse
    {
        $owner = User::factory()->create(['locale' => $locale]);
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->attach($this->location->id);

        return $this->actingAs($owner)
            ->withSession(['scope.organisation_id' => $this->org->id, 'scope.location_id' => $this->location->id])
            ->get('/');
    }
}
