<?php

namespace Tests\Feature\Till;

use App\Actions\Bar\CommitOrder;
use App\Actions\Till\OpenTill;
use App\Enums\DashboardAlert;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\TillReportPage;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Reports\TillReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 380 — «Cada noche» said the box had to be counted («hay que contarlo»), but the close never blocks (366) and staff
 * could pick «No se cuenta hoy»: the till closed, the bar's €1.50 carried, and nothing told the owner. Now the words say what
 * it is (the close starts on «Contar ahora»), and a skipped «Cada noche» box is noted: a line at the close, an audit row, the
 * till report, and a dashboard count.
 */
class NightlyBoxSkipTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        // The tester's set-up: the bar in its own box counted «Cada noche», the fees in their own box «Solo al vaciarlo».
        Settings::set('cash_box_bar', 'own', SettingType::STRING, (string) $this->sede->id);
        Settings::set('cash_box_fees', 'own', SettingType::STRING, (string) $this->sede->id);
        Settings::set('count_bar_nightly', true, SettingType::BOOL, (string) $this->sede->id);
        Settings::set('count_fees_nightly', false, SettingType::BOOL, (string) $this->sede->id);
    }

    /** A till with €1.50 in the bar box, at the close screen. */
    private function atTheClose(): TillSession
    {
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 150, 'stock' => 10]);
        (new CommitOrder)->handle($this->sede, [['article_id' => $article->id, 'qty' => 1]], ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => 150]);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);

        return $session;
    }

    // --- 1. The settings say what it is ---------------------------------------------------------------------------------------

    public function test_every_night_is_worded_as_where_the_close_starts_and_never_as_a_must(): void
    {
        Livewire::test(EditLocation::class, ['record' => $this->sede->id])
            ->assertSee('Al cerrar viene marcado «Contar ahora». Si un día no se cuenta, queda anotado.')
            ->assertSee('Al cerrar viene marcado «No se cuenta hoy»; lo que tiene pasa al día siguiente.')
            ->assertDontSee('hay que contarlo');
    }

    // --- 2. A «Cada noche» box skipped: the till closes, it is noted -----------------------------------------------------------

    public function test_skipping_an_every_night_box_closes_the_till_shows_the_note_and_audits_it(): void
    {
        $session = $this->atTheClose();

        $screen = Livewire::test(TillScreen::class)->call('startClose')
            ->assertSet('potCountNow.BAR', true)
            ->assertDontSeeHtml('data-pot-nightly-note="BAR"')
            ->set('potCountNow.BAR', false)
            ->assertSeeHtml('data-pot-nightly-note="BAR"')
            ->assertSee('Esta sede lo cuenta cada noche: quedará anotado que hoy no se contó.');
        $screen->set('countInput', '100')->call('submitCount')->assertSet('countSubmitted', true);

        $this->assertNotNull($session->fresh()->closed_at, 'never blocked (366)');
        $this->assertNull($session->fresh()->getRawOriginal('bar_counted_cents'));
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'till.box_not_counted')->sole();
        $this->assertSame(['pot' => 'BAR', 'expected_cents' => 150, 'closed_by' => $this->owner->id], array_intersect_key($audit->after, ['pot' => 1, 'expected_cents' => 1, 'closed_by' => 1]));
    }

    // --- 3. A «Solo al vaciarlo» box skipped: nothing to note ---------------------------------------------------------------

    public function test_skipping_an_only_when_emptied_box_writes_nothing_and_shows_no_note(): void
    {
        $this->atTheClose();

        Livewire::test(TillScreen::class)->call('startClose')
            ->assertSet('potCountNow.FEES', false)
            ->assertDontSeeHtml('data-pot-nightly-note="FEES"')
            ->set('countInput', '100')->set('potCountInput.BAR', '1.50')->call('submitCount');

        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'till.box_not_counted')->count());
    }

    // --- 4. The till report -----------------------------------------------------------------------------------------------------

    public function test_the_till_report_says_it_is_counted_every_night_and_lists_it_under_botes_sin_contar(): void
    {
        $session = $this->atTheClose();
        Livewire::test(TillScreen::class)->call('startClose')->set('potCountNow.BAR', false)->set('countInput', '100')->call('submitCount');

        $period = Period::thisWeek($this->sede);
        $row = (new TillReport($this->org->id, [$this->sede->id], $period))->primary()->rows[0];
        $this->assertSame(__('no contado (se cuenta cada noche)'), $row['barra_contado']);

        $listed = (new TillReport($this->org->id, [$this->sede->id], $period))->onlyUncountedBoxes()->primary()->rows;
        $this->assertCount(1, $listed);
        Livewire::withQueryParams(['sin_contar' => 1, 'period' => 'week'])->test(TillReportPage::class)->assertSet('sin_contar', true)
            ->assertSee(__('no contado (se cuenta cada noche)'));
        $this->assertNotNull($session->fresh()->closed_at);
    }

    // --- 5. The dashboard --------------------------------------------------------------------------------------------------------

    public function test_the_dashboard_counts_the_boxes_left_uncounted_this_week_for_a_manager_not_staff(): void
    {
        $this->atTheClose();
        Livewire::test(TillScreen::class)->call('startClose')->set('potCountNow.BAR', false)->set('countInput', '100')->call('submitCount');

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $html = Livewire::actingAs($manager)->test(Dashboard::class)->html();
        $this->assertStringContainsString(e(DashboardAlert::TILL_BOXES_UNCOUNTED->label(1)), $html);
        $this->assertStringContainsString(e(TillReportPage::getUrl(['sin_contar' => 1, 'period' => 'week'])), $html);

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->sede->id]);
        $this->assertStringNotContainsString(e(DashboardAlert::TILL_BOXES_UNCOUNTED->label(1)), Livewire::actingAs($staff)->test(Dashboard::class)->html());
    }
}
