<?php

namespace Tests\Feature\Till;

use App\Actions\Alerts\EvaluateAlerts;
use App\Actions\Bar\CommitOrder;
use App\Actions\Roles\SetRolePermission;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\AlertType;
use App\Enums\BatchStatus;
use App\Enums\DashboardAlert;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\TillSessionStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\TillReportPage;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Livewire\Counter\StockScreen;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\AlertMessage;
use App\Support\Alerts\CurrentAlerts;
use App\Support\CounterOperator;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Reports\TillReport;
use Database\Seeders\RolePermissionSeeder;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 366 — Shane: "Wouldn't let me shut yesterday's till, not sure why — even with a note in the box it didn't work."
 * Ben: "Just remove all these checks, as long as there's a log for the owner to see and they can see an easy report."
 *
 * Closing the till never gets blocked by a difference: the note is optional, the flower count can go on without a
 * reason, and anything unexpected is reported and said as a system error — never dressed up as "write a note". The
 * owner sees every difference in an audit entry, on Informes → Cajas («Solo con diferencia», «Sin explicar»), on the
 * till page and as one line on the dashboard and in the morning summary.
 */
class CloseNeverBlocksTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->manager = User::factory()->create(['pin' => '2345']);
        $this->manager->assignRole(Role::MANAGER->value);
        $this->manager->locations()->sync([$this->sede->id]);
        $this->actingAs($this->manager);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->manager);
    }

    private function openTill(int $floatCents = 10000): TillSession
    {
        return (new OpenTill)->handle($this->sede, 'Caja 1', $floatCents, ['operator_id' => $this->manager->id]);
    }

    private function audit(string $action): ?AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->where('action', $action)->latest('id')->first();
    }

    // --- 1. Beyond the tolerance with no note: it closes, and the owner has the entry -------------------------------------

    public function test_a_close_beyond_the_tolerance_with_no_note_closes_and_is_audited_with_a_null_note(): void
    {
        $session = $this->openTill(10000);

        Livewire::test(TillScreen::class)->call('startClose')
            ->set('countInput', '80')->call('submitCount')
            ->assertSet('flashMessage', 'Caja cerrada.')
            ->assertSet('countSubmitted', true)
            ->assertSet('variance', -2000);

        $this->assertSame(TillSessionStatus::CLOSED, $session->fresh()->status);
        $log = $this->audit('till.closed_with_variance');
        $this->assertNotNull($log, 'A close beyond the tolerance leaves an entry for the owner.');
        $after = $log->after;
        $this->assertNull($after['note']);
        $this->assertSame(10000, $after['expected_cents']);
        $this->assertSame(8000, $after['counted_cents']);
        $this->assertSame(-2000, $after['variance_cents']);
        $this->assertSame($this->sede->id, $after['location_id']);
        $this->assertSame('Caja 1', $after['terminal']);
        $this->assertSame($this->manager->id, $log->actor_id);
    }

    public function test_within_the_tolerance_there_is_no_variance_entry_and_a_note_is_still_kept(): void
    {
        $session = $this->openTill(10000);
        (new CloseTill)->handle($session, 9800, $this->manager, 'Dos euros de cambio');

        $this->assertNull($this->audit('till.closed_with_variance'));
        $this->assertSame('Dos euros de cambio', $session->fresh()->notes);
    }

    public function test_the_screen_offers_an_optional_note_without_an_amount(): void
    {
        $this->openTill(10000);

        Livewire::test(TillScreen::class)->call('startClose')
            ->assertSee('¿Quieres dejar una nota? (opcional)')
            ->assertDontSeeHtml('data-till-expected');
    }

    // --- 2. Shane's case: the bar counted at 2.50, the fees not counted ------------------------------------------------------

    public function test_shanes_close_with_the_bar_counted_and_the_fees_not_counted_closes_and_the_audit_carries_the_bar(): void
    {
        Settings::set('cash_box_bar', 'own', SettingType::STRING, (string) $this->sede->id); // 373: bar + fees in their own boxes
        Settings::set('cash_box_fees', 'own', SettingType::STRING, (string) $this->sede->id);
        $session = $this->openTill(10000);
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 1000, 'stock' => 10]);
        (new CommitOrder)->handle($this->sede, [['article_id' => $article->id, 'qty' => 1]], ['operator_id' => $this->manager->id, 'till_session_id' => $session->id, 'cash_cents' => 1000]);

        Livewire::test(TillScreen::class)->call('startClose')
            ->set('countInput', '100')
            ->set('potCountNow.BAR', true)->set('potCountInput.BAR', '2.50')
            ->set('potCountNow.FEES', false)
            ->call('submitCount')
            ->assertSet('flashMessage', 'Caja cerrada.')
            ->assertSet('potResults.BAR.variance', -750)
            ->assertSet('potResults.FEES.counted', null);

        $after = $this->audit('till.closed_with_variance')?->after;
        $this->assertNotNull($after);
        $this->assertSame(1000, $after['bar_expected_cents']);
        $this->assertSame(250, $after['bar_counted_cents']);
        $this->assertSame(-750, $after['bar_variance_cents']);
        $this->assertNull($after['fees_counted_cents']);
        $this->assertSame(0, $after['variance_cents']);
    }

    // --- 3. Anything unexpected is reported and said as a system error, never as "write a note" -----------------------------

    public function test_an_unexpected_database_error_is_reported_and_shown_as_a_system_error(): void
    {
        Exceptions::fake();
        $session = $this->openTill(10000);
        $this->app->bind(CloseTill::class, fn () => new class extends CloseTill
        {
            public function handle(TillSession $session, int $countedCents, User $closedBy, ?string $note = null, array $potCounts = []): TillSession
            {
                throw new QueryException('sqlite', 'update till_sessions set …', [], new Exception('disk I/O error'));
            }
        });

        Livewire::test(TillScreen::class)->call('startClose')
            ->set('countInput', '100')->call('submitCount')
            ->assertSet('flashMessage', 'No se pudo cerrar la caja por un error del sistema. Ya está avisado. Inténtalo de nuevo o avisa al responsable.')
            ->assertSet('flashType', 'error')
            ->assertSet('countSubmitted', false)
            ->assertDontSee('Hace falta una nota');

        Exceptions::assertReported(QueryException::class);
        $this->assertSame(TillSessionStatus::OPEN, $session->fresh()->status);
    }

    public function test_a_database_error_elsewhere_on_the_counter_is_reported_and_not_dressed_as_a_refusal(): void
    {
        // The sweep: the counter's broad catches put a QueryException FIRST (reported, said as the system's fault) ahead of
        // the domain refusals — here Existencias, whose catch used to echo the raw exception message to staff.
        Exceptions::fake();
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'initial_cg' => 10000, 'remaining_cg' => 5000, 'reserve_cg' => 5000, 'status' => BatchStatus::OPEN]);
        StockMovement::creating(fn () => throw new QueryException('sqlite', 'insert into stock_movements …', [], new Exception('disk I/O error')));

        Livewire::test(StockScreen::class)->call('openBatch', $batch->id)->call('topUpJar', '10')
            ->assertSet('flashMessage', 'No se pudo completar por un error del sistema. Ya está avisado. Inténtalo de nuevo o avisa al responsable.')
            ->assertDontSee('disk I/O error');

        // Caught as a PDOException, so "database is locked" — which a transaction re-throws as a DeadlockException, not a
        // QueryException — is caught the same way.
        Exceptions::assertReported(QueryException::class);
        $this->assertSame(5000, $batch->fresh()->reserve_cg->centigrams);
    }

    // --- 4. The flower count can go on without a reason ----------------------------------------------------------------------

    public function test_the_flower_count_goes_on_without_a_reason_and_records_it_unexplained(): void
    {
        // A manager holds reasons.optional (356) and is never asked; a club that revoked it is the case being asked.
        (new SetRolePermission)->handle(Role::MANAGER, 'reasons.optional', false, tap(User::factory()->create(), fn (User $o) => $o->assignRole(Role::OWNER->value)));
        $this->openTill(10000);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'initial_cg' => 100000, 'remaining_cg' => 90000, 'status' => BatchStatus::OPEN]);

        Livewire::test(TillScreen::class)->call('startClose')
            ->assertSet('reweighing', true)
            ->set('reweighCounts', [$batch->id => '800'])->call('submitReweigh')
            ->assertSet('reweighAsking', true)
            ->assertSee('Seguir sin motivo')
            ->call('submitReweighWithoutReason')
            ->assertSet('reweighDone', true)
            ->assertSet('reweighAsking', false)
            ->set('countInput', '100')->call('submitCount')
            ->assertSet('flashMessage', 'Caja cerrada.');

        $this->assertSame(80000, $batch->fresh()->remaining_cg->centigrams);
        $take = StockTake::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertNull($take->reason);
        $unexplained = $this->audit('stock.count_unexplained');
        $this->assertNotNull($unexplained);
        $this->assertSame($take->id, (string) $unexplained->getAttribute('auditable_id'));
        $this->assertSame($this->sede->id, $unexplained->after['location_id']);
    }

    // --- 5. What is still refused --------------------------------------------------------------------------------------------

    public function test_without_till_close_the_close_is_refused(): void
    {
        $session = $this->openTill(10000);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // till.open, not till.close
        $staff->locations()->sync([$this->sede->id]);
        CounterOperator::set($staff);

        Livewire::test(TillScreen::class)->call('startClose')
            ->set('countInput', '100')->call('submitCount')
            ->assertSet('flashMessage', 'No tienes permiso para cerrar la caja.')
            ->assertSet('countSubmitted', false);
        $this->assertSame(TillSessionStatus::OPEN, $session->fresh()->status);
    }

    public function test_an_invalid_or_negative_count_is_refused(): void
    {
        $session = $this->openTill(10000);

        Livewire::test(TillScreen::class)->call('startClose')
            ->set('countInput', 'ochenta')->call('submitCount')
            ->assertSet('flashMessage', 'El importe contado no es válido.')
            ->set('countInput', '-5')->call('submitCount')
            ->assertSet('flashMessage', 'El importe contado no es válido.')
            ->assertSet('countSubmitted', false);
        $this->assertSame(TillSessionStatus::OPEN, $session->fresh()->status);

        // And the action itself refuses a negative pot, as a refusal of the input (not a system error).
        Settings::set('cash_box_bar', 'own', SettingType::STRING, (string) $this->sede->id); // 373: bar + fees in their own boxes
        Settings::set('cash_box_fees', 'own', SettingType::STRING, (string) $this->sede->id);
        $pots = (new OpenTill)->handle($this->sede, 'Caja 2', 0, ['operator_id' => $this->manager->id]);
        $this->assertSame(['BAR', 'FEES'], $pots->own_boxes);
        $this->expectException(InvalidArgumentException::class);
        (new CloseTill)->handle($pots->fresh(), 0, $this->manager, null, ['BAR' => -100]);
    }

    public function test_an_already_closed_till_is_refused(): void
    {
        $session = $this->openTill(10000);
        $screen = Livewire::test(TillScreen::class)->call('startClose')->set('countInput', '100');
        (new CloseTill)->handle($session, 10000, $this->manager); // closed from another terminal meanwhile

        $screen->call('submitCount')->assertSet('countSubmitted', false);
        $this->assertSame(1, TillSession::query()->withoutGlobalScopes()->where('status', TillSessionStatus::CLOSED)->count());
        $this->assertNull($this->audit('till.closed_with_variance'));
    }

    // --- 6. Informes → Cajas: «Solo con diferencia» and «Sin explicar» -------------------------------------------------------

    /** @return array{0: TillSession, 1: TillSession, 2: TillSession} within tolerance · beyond with a note · beyond without */
    private function threeCloses(): array
    {
        $fine = $this->openTill(10000);
        (new CloseTill)->handle($fine, 9900, $this->manager);
        $explained = (new OpenTill)->handle($this->sede, 'Caja 2', 10000, ['operator_id' => $this->manager->id]);
        (new CloseTill)->handle($explained, 8000, $this->manager, 'Pagado un proveedor sin apuntar');
        $unexplained = (new OpenTill)->handle($this->sede, 'Caja 3', 10000, ['operator_id' => $this->manager->id]);
        (new CloseTill)->handle($unexplained, 12000, $this->manager);

        return [$fine->fresh(), $explained->fresh(), $unexplained->fresh()];
    }

    public function test_the_report_filter_lists_only_closes_beyond_the_tolerance_and_flags_the_one_without_a_note(): void
    {
        [$fine, $explained, $unexplained] = $this->threeCloses();

        $all = collect((new TillReport($this->org->id, [$this->sede->id], Period::thisWeek($this->sede)))->tables())->firstWhere('key', 'sessions');
        $this->assertCount(3, $all->rows);

        $only = collect((new TillReport($this->org->id, [$this->sede->id], Period::thisWeek($this->sede)))->onlyWithVariance()->tables())->firstWhere('key', 'sessions');
        $this->assertEqualsCanonicalizing(['Caja 2', 'Caja 3'], array_column($only->rows, 'terminal'));
        $byTerminal = collect($only->rows)->keyBy('terminal');
        $this->assertSame('', $byTerminal['Caja 2']['sin_explicar']);
        $this->assertSame('Sin explicar', $byTerminal['Caja 3']['sin_explicar']);
        $this->assertStringContainsString($unexplained->id, (string) $byTerminal['Caja 3']['fecha__url']);
        $this->assertStringContainsString($explained->id, (string) $byTerminal['Caja 2']['fecha__url']);

        $page = Livewire::withQueryParams(['diferencia' => '1', 'period' => 'week'])->test(TillReportPage::class)
            ->assertSet('diferencia', true)->assertSet('period', 'week');
        $html = $page->html();
        $this->assertStringContainsString('Solo con diferencia', $html);
        $this->assertStringContainsString('csc-cell-warning', $html);
        $this->assertStringContainsString($unexplained->id, $html);
        $this->assertStringNotContainsString($fine->id, $html);
    }

    public function test_the_till_page_leads_with_the_difference(): void
    {
        [, , $unexplained] = $this->threeCloses();

        $this->get(TillSessionResource::getUrl('view', ['record' => $unexplained]))
            ->assertOk()
            ->assertSee('Diferencia en el cierre')
            ->assertSee('Sin nota')
            ->assertSee('+20.00');
    }

    // --- 7. The dashboard and the morning summary ----------------------------------------------------------------------------

    public function test_the_dashboard_and_the_morning_summary_count_this_weeks_unexplained_closes_and_link_to_the_report(): void
    {
        $this->threeCloses();
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        $html = Livewire::actingAs($owner)->test(Dashboard::class)->html();
        $this->assertStringContainsString(e(DashboardAlert::TILL_CLOSES_UNEXPLAINED->label(1)), $html);
        $this->assertStringContainsString('Cierres con diferencia sin explicar: 1 esta semana', $html);
        $this->assertStringContainsString('informes/cajas?diferencia=1&amp;period=week', $html);

        $alerts = collect(CurrentAlerts::for($this->org))->where('type', AlertType::TILL_CLOSES_UNEXPLAINED);
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts->first()['detail']['count']);
        $this->assertSame($this->sede->id, $alerts->first()['location_id']);

        (new EvaluateAlerts)->handle($this->org);
        /** @var Collection<int, OwnerAlertState> $states */
        $states = OwnerAlertState::query()->withoutGlobalScopes()->active()->with('location')->get();
        $this->assertStringContainsString('Cierres con diferencia sin explicar: 1 esta semana', AlertMessage::text($states));
    }

    public function test_with_every_difference_explained_there_is_no_dashboard_line(): void
    {
        $session = $this->openTill(10000);
        (new CloseTill)->handle($session, 8000, $this->manager, 'Pagado un proveedor');
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        $this->assertStringNotContainsString('Cierres con diferencia sin explicar', Livewire::actingAs($owner)->test(Dashboard::class)->html());
        $this->assertCount(0, collect(CurrentAlerts::for($this->org))->where('type', AlertType::TILL_CLOSES_UNEXPLAINED));
    }
}
