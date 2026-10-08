<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\CommitStockTake;
use App\Actions\Stock\RecordStockCountLine;
use App\Actions\Stock\SelectBatch;
use App\Actions\Stock\StartStockCount;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockCountReason;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Filament\Pages\Inventario;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Livewire\Counter\TillSession;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\ManagerApproval;
use App\Support\Settings;
use App\Support\Weight;
use App\Support\ZReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 360 — after 359, the go-live cleanup and the evening close:
 *
 *  - Inventario counts each weight batch's JAR and its SEALED RESERVE on one row, either left blank to mean "untouched",
 *    can list the batches the old reweigh zeroed, takes one shared reason, and never stops at the ceiling;
 *  - the batch's Ajuste chooses Bote / Reserva;
 *  - the end-of-day weigh asks ONE reason, only when the count is off, never saying which jar or by how much.
 */
class ReserveCountAndCloseReasonTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Genetic $genetic;

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
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia', 'product_type' => ProductType::FLOWER]);
    }

    private function batch(int $jar, int $reserve = 0, BatchStatus $status = BatchStatus::OPEN, ?int $initial = null): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'initial_cg' => $initial ?? $jar + $reserve, 'remaining_cg' => $jar, 'reserve_cg' => $reserve, 'status' => $status,
            'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
    }

    private function line(StockTake $take, Batch $batch): StockTakeLine
    {
        return $take->lines()->where('countable_id', $batch->id)->sole();
    }

    /** @return list<array{int, bool}> [qty_cg, on_reserve] of the batch's adjustments */
    private function adjustments(Batch $batch): array
    {
        return StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::ADJUSTMENT->value)
            ->orderBy('id')->get()->map(fn (StockMovement $m): array => [$m->qty_cg->centigrams, (bool) $m->on_reserve])->all();
    }

    /** [jar, reserve] in centigrams */
    private function figures(Batch $batch): array
    {
        $fresh = $batch->fresh();

        return [$fresh->remaining_cg->centigrams, $fresh->reserve_cg->centigrams];
    }

    /** A user with stock.take who does NOT hold reasons.optional (staff, granted the count). */
    private function staffCounter(): User
    {
        $this->setRolePermission(Role::STAFF, 'stock.take', true);
        $this->setRolePermission(Role::STAFF, 'till.close', true);
        $staff = User::factory()->create(['pin' => '3456']);
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->sede->id]);

        return $staff;
    }

    // --- 1. Two counts on one row ---------------------------------------------------------------------------------------------

    public function test_one_row_counts_the_jar_and_the_reserve_and_applies_them_separately(): void
    {
        $batch = $this->batch(2000); // jar 20 g, reserve 0 — the go-live state
        $take = (new StartStockCount)->handle($this->sede, $this->owner);
        $this->assertSame(1, $take->lines()->where('countable_id', $batch->id)->count(), 'ONE row per batch, not a separate reserve line');

        (new RecordStockCountLine)->handle($this->line($take, $batch), '18', $this->owner, reserve: '400');
        $line = $this->line($take, $batch)->fresh();
        $this->assertSame([1800, 2000, 40000, 0], [$line->counted_cg->centigrams, $line->expected_cg->centigrams, $line->counted_reserve_cg->centigrams, $line->expected_reserve_cg->centigrams]);

        (new CommitStockTake)->applyCount($take->fresh(), $this->owner, [], StockCountReason::RESERVE_REGULARISATION);

        $this->assertSame([1800, 40000], $this->figures($batch));
        $this->assertSame([[-200, false], [40000, true]], $this->adjustments($batch), 'two distinct movements: the jar and the reserve');

        $report = Inventario::reportView($take->fresh())->render();
        $this->assertStringContainsString(__('La reserva sellada'), $report);
        $this->assertStringContainsString(Weight::fromCentigrams(40000)->formatted(), $report);
        $this->assertStringContainsString('+'.Weight::fromCentigrams(40000)->formatted(), $report, 'the reserve variance has its own column');
    }

    // --- 2. Blank means untouched ---------------------------------------------------------------------------------------------

    public function test_a_blank_figure_is_left_untouched(): void
    {
        $onlyReserve = $this->batch(5000, 10000);
        $onlyJar = $this->batch(3000, 20000);
        $take = (new StartStockCount)->handle($this->sede, $this->owner);

        (new RecordStockCountLine)->handle($this->line($take, $onlyReserve), '', $this->owner, reserve: '120');
        (new RecordStockCountLine)->handle($this->line($take, $onlyJar), '29', $this->owner, reserve: '');
        (new CommitStockTake)->applyCount($take->fresh(), $this->owner, [], StockCountReason::RESERVE_REGULARISATION);

        $this->assertSame([5000, 12000], $this->figures($onlyReserve));
        $this->assertSame([[2000, true]], $this->adjustments($onlyReserve));
        $this->assertSame([2900, 20000], $this->figures($onlyJar));
        $this->assertSame([[-100, false]], $this->adjustments($onlyJar));
    }

    // --- 3. Batches the old reweigh zeroed --------------------------------------------------------------------------------------

    public function test_a_batch_zeroed_by_a_past_reweigh_is_listed_corrected_reopened_and_sellable(): void
    {
        $zeroed = $this->batch(0, 0, BatchStatus::CLOSED, initial: 50000);
        $past = StockTake::create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'opened_by' => $this->owner->id,
            'opened_at' => now()->subWeek(), 'status' => StockTakeStatus::COMMITTED]);
        StockMovement::create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'stockable_type' => $zeroed->getMorphClass(),
            'stockable_id' => $zeroed->id, 'qty_cg' => -45000, 'type' => StockMovementType::ADJUSTMENT, 'reason' => 'Recuento de inventario', 'stock_take_id' => $past->id]);
        $untouchedZero = $this->batch(0, 0, initial: 1000); // another empty batch, left blank

        $this->assertTrue(StartStockCount::suggestsZeroBatches($this->sede), 'the cleanup case turns «Incluir lotes a cero» on by default');

        $without = (new StartStockCount)->handle($this->sede, $this->owner);
        $this->assertFalse($without->lines()->where('countable_id', $zeroed->id)->exists(), 'off: empty batches are not listed');
        $without->update(['status' => StockTakeStatus::CANCELLED]);

        $take = (new StartStockCount)->handle($this->sede, $this->owner, includeZero: true);
        $this->assertTrue($take->lines()->where('countable_id', $zeroed->id)->exists());
        (new RecordStockCountLine)->handle($this->line($take, $zeroed), '20', $this->owner, reserve: '300');

        // The other empty batch, never touched, does not block «Aplicar ajustes».
        (new CommitStockTake)->applyCount($take->fresh(), $this->owner, [], StockCountReason::RESERVE_REGULARISATION);

        $this->assertSame([2000, 30000], $this->figures($zeroed));
        $this->assertSame(BatchStatus::OPEN, $zeroed->fresh()->status, 'corrected upward: back on the counter');
        $this->assertSame($zeroed->id, (new SelectBatch)->fefo($this->genetic, $this->sede)?->id);
        $this->assertSame([0, 0], $this->figures($untouchedZero));
    }

    // --- 4. One shared reason -------------------------------------------------------------------------------------------------

    public function test_one_shared_reason_covers_thirty_differences_with_no_typing(): void
    {
        $this->setRolePermission(Role::MANAGER, ManagerApproval::PERMISSION, false); // must not lean on 356
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);

        $batches = collect(range(1, 30))->map(fn (): Batch => $this->batch(1000));
        $take = (new StartStockCount)->handle($this->sede, $manager);
        foreach ($batches as $batch) {
            (new RecordStockCountLine)->handle($this->line($take, $batch), '10', $manager, reserve: '250');
        }

        (new CommitStockTake)->applyCount($take->fresh(), $manager, [], StockCountReason::RESERVE_REGULARISATION);

        $reasons = StockMovement::query()->withoutGlobalScopes()->where('stock_take_id', $take->id)->where('type', StockMovementType::ADJUSTMENT->value)->pluck('reason');
        $this->assertCount(30, $reasons);
        $this->assertTrue($reasons->every(fn (string $r): bool => str_contains($r, __('Regularización: alta de la reserva sellada'))));
    }

    public function test_without_a_shared_reason_a_big_difference_still_needs_its_own(): void
    {
        $this->setRolePermission(Role::MANAGER, ManagerApproval::PERMISSION, false);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $batch = $this->batch(1000);
        $take = (new StartStockCount)->handle($this->sede, $manager);
        (new RecordStockCountLine)->handle($this->line($take, $batch), '10', $manager, reserve: '250');

        $this->expectException(\DomainException::class);
        (new CommitStockTake)->applyCount($take->fresh(), $manager, []);
    }

    // --- 5. The ceiling never blocks a correction -----------------------------------------------------------------------------

    public function test_a_correction_over_a_block_ceiling_is_applied_and_warned(): void
    {
        $matrix = Settings::DEFAULTS['enforcement'];
        $matrix['stock']['ceiling'] = 'BLOCK';
        Settings::set('enforcement', $matrix, SettingType::JSON);
        $batch = $this->batch(1000); // no active members ⇒ a ceiling of 0 g
        $take = (new StartStockCount)->handle($this->sede, $this->owner);
        (new RecordStockCountLine)->handle($this->line($take, $batch), '10', $this->owner, reserve: '500');

        Livewire::test(Inventario::class, ['count' => $take->id, 'mode' => 'review'])
            ->assertSeeHtml('data-count-ceiling');

        (new CommitStockTake)->applyCount($take->fresh(), $this->owner, [], StockCountReason::RESERVE_REGULARISATION);
        $this->assertSame([1000, 50000], $this->figures($batch));
    }

    // --- 6. Ajuste: Bote / Reserva --------------------------------------------------------------------------------------------

    public function test_the_batch_ajuste_adjusts_only_the_figure_chosen(): void
    {
        $batch = $this->batch(5000, 10000);

        // Prompt 368 — «Añadir» / «Quitar» with a positive amount (no more typing a negative).
        Livewire::test(ListBatches::class)->callTableAction('adjust', $batch, ['bucket' => 'reserve', 'mode' => 'add', 'amount' => '25', 'reason_pick' => 'count'])
            ->assertHasNoTableActionErrors();
        $this->assertSame([5000, 12500], $this->figures($batch));

        Livewire::test(ListBatches::class)->callTableAction('adjust', $batch, ['bucket' => 'jar', 'mode' => 'remove', 'amount' => '5', 'reason_pick' => 'weighing'])
            ->assertHasNoTableActionErrors();
        $this->assertSame([4500, 12500], $this->figures($batch));
        $this->assertSame([[2500, true], [-500, false]], $this->adjustments($batch));
    }

    // --- 7. Permissions unchanged ---------------------------------------------------------------------------------------------

    public function test_staff_without_the_count_permissions_cannot_open_inventario(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->sede->id]);
        $this->actingAs($staff);

        $this->assertFalse(Inventario::canAccess());
    }

    // --- 10–14. The end-of-day weigh: one reason, only when it's off ----------------------------------------------------------

    private function close(User $operator): Testable
    {
        $this->actingAs($operator);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($operator);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);

        return Livewire::test(TillSession::class)->call('startClose')->assertSet('reweighing', true);
    }

    /** A jar the day touched (initial above jar + reserve), so it is in the evening count. */
    private function touched(int $jar, int $reserve = 0): Batch
    {
        return $this->batch($jar, $reserve, initial: $jar + $reserve + 5000);
    }

    public function test_a_close_count_that_matches_asks_nothing(): void
    {
        $batch = $this->touched(10000);
        $this->close($this->staffCounter())
            ->set('reweighCounts', [$batch->id => '100.5']) // 0.5 g over: within tolerance
            ->call('submitReweigh')
            ->assertSet('reweighAsking', false)
            ->assertSet('reweighDone', true)
            ->assertDontSeeHtml('data-reweigh-reason-box');

        $this->assertNull(StockTake::query()->withoutGlobalScopes()->latest('opened_at')->first()->reason);
    }

    public function test_one_jar_off_asks_one_blind_question_and_its_answer_lands_on_the_take_and_the_movement(): void
    {
        $batch = $this->touched(10000);
        $till = $this->close($this->staffCounter())
            ->set('reweighCounts', [$batch->id => '90'])
            ->call('submitReweigh')
            ->assertSet('reweighDone', false)
            ->assertSet('reweighAsking', true);

        $html = $till->html();
        $this->assertSame(1, substr_count($html, 'data-reweigh-reason-box'));
        $box = substr($html, (int) strpos($html, 'data-reweigh-reason-box'), 3000);
        $this->assertStringContainsString(__('El recuento no cuadra — ¿qué ha pasado?'), $box);
        $this->assertStringNotContainsString('Amnesia', $box, 'blind: no jar named');
        $this->assertDoesNotMatchRegularExpression('/\d+[.,]\d{2} g/', $box, 'blind: no amount');
        $this->assertSame(0, StockTake::query()->withoutGlobalScopes()->count(), 'nothing committed before the answer');

        $till->call('submitReweigh', 'WEIGHING_ERROR')->assertSet('reweighDone', true);

        $take = StockTake::query()->withoutGlobalScopes()->sole();
        $this->assertSame(__('Error al pesar'), $take->reason);
        $this->assertSame(__('Error al pesar'), StockMovement::query()->withoutGlobalScopes()->where('stock_take_id', $take->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole()->reason);

        // The manager reads it on the till report, beside the per-jar variance.
        $report = ZReport::for(TillSessionModel::query()->withoutGlobalScopes()->sole());
        $this->assertSame(__('Error al pesar'), $report['stock_count_reason']);
        $this->assertCount(1, $report['stock_count_lines']);
        $this->assertStringContainsString('-'.Weight::fromCentigrams(1000)->formatted(), $report['stock_count_lines'][0]);
    }

    public function test_two_jars_off_and_one_not_counted_still_ask_once_and_the_answer_covers_all_three(): void
    {
        $a = $this->touched(10000);
        $b = $this->touched(20000);
        $c = $this->touched(30000);
        $till = $this->close($this->staffCounter())
            ->set('reweighCounts', [$a->id => '80', $b->id => '150'])
            ->call('toggleNotCounted', $c->id);

        $this->assertStringNotContainsString('reweighReasons', $till->html(), 'no per-jar reason field');

        $till->call('submitReweigh')->assertSet('reweighAsking', true);
        $this->assertSame(1, substr_count($till->html(), 'data-reweigh-reason-box'));

        $till->call('submitReweigh', 'OTHER', 'Báscula descalibrada')->assertSet('reweighDone', true);

        $take = StockTake::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Báscula descalibrada', $take->reason);
        $reasons = StockMovement::query()->withoutGlobalScopes()->where('stock_take_id', $take->id)->where('type', StockMovementType::ADJUSTMENT->value)->pluck('reason')->all();
        $this->assertSame(['Báscula descalibrada', 'Báscula descalibrada'], $reasons);
        $this->assertSame('Báscula descalibrada', $take->lines()->where('countable_id', $c->id)->sole()->not_counted_reason);
    }

    public function test_a_forgotten_top_up_absorbed_by_the_reserve_does_not_ask(): void
    {
        $batch = $this->touched(5000, 10000);
        $this->close($this->staffCounter())
            ->set('reweighCounts', [$batch->id => '150']) // 10 g over, a bag opened without «Rellenar»
            ->call('submitReweigh')
            ->assertSet('reweighAsking', false)
            ->assertSet('reweighDone', true);
    }

    public function test_a_reasons_optional_operator_is_not_asked_and_it_is_recorded_as_manager_approved(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $batch = $this->touched(10000);

        $this->close($manager)
            ->set('reweighCounts', [$batch->id => '90'])
            ->call('submitReweigh')
            ->assertSet('reweighAsking', false)
            ->assertSet('reweighDone', true);

        $take = StockTake::query()->withoutGlobalScopes()->sole();
        $this->assertSame(ManagerApproval::reason(), $take->reason);
        $this->assertSame(ManagerApproval::reason(), StockMovement::query()->withoutGlobalScopes()->where('stock_take_id', $take->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole()->reason);
    }
}
