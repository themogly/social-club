<?php

namespace Tests\Feature\Counter;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Stock\RecountBatch;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\StockScreen;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\CounterScreens;
use App\Support\ManagerApproval;
use App\Support\TrainingMode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 364 — «Existencias», the counter's sixth screen. Ben: "a button in a separate page in the counter — something like
 * 'view stock' for the staff — where they can quickly adjust the batches, like update weights, view what top-ups are in the
 * club, and top up a jar". Selling stays in the dispensary; stock work moves here.
 */
class StockScreenTest extends TestCase
{
    use ChangesRolePermissions, PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Location $norte;

    private Genetic $amnesia;

    private Genetic $critical;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->amnesia = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia', 'product_type' => ProductType::FLOWER]);
        $this->critical = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Critical', 'product_type' => ProductType::FLOWER]);
    }

    private function user(Role $role, string $pin = '3456'): User
    {
        $user = User::factory()->create(['pin' => Hash::make($pin)]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->sede->id, $this->norte->id]);

        return $user;
    }

    private function batch(Genetic $genetic, int $jar, int $reserve = 0, ?Location $at = null): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => ($at ?? $this->sede)->id,
            'initial_cg' => $jar + $reserve, 'remaining_cg' => $jar, 'reserve_cg' => $reserve, 'status' => BatchStatus::OPEN,
            'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
    }

    private function screen(User $operator, array $params = []): Testable
    {
        $this->actingAs($operator);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($operator);

        return Livewire::withQueryParams($params)->test(StockScreen::class);
    }

    /** [jar, reserve] in centigrams */
    private function figures(Batch $batch): array
    {
        $fresh = $batch->fresh();

        return [$fresh->remaining_cg->centigrams, $fresh->reserve_cg->centigrams];
    }

    // --- 1. Screen and gate --------------------------------------------------------------------------------------------------

    public function test_staff_see_the_sedes_jars_and_reserve_and_nothing_from_another_sede(): void
    {
        $this->batch($this->amnesia, 2000, 3000);
        $this->batch($this->critical, 1500, 0, $this->norte);
        $staff = $this->user(Role::STAFF);

        $this->screen($staff)->assertOk()->assertSee('Amnesia')->assertSee('20.00 g')->assertSee('30.00 g')->assertDontSee('Critical');

        $this->assertTrue(collect(CounterScreens::forUser($staff))->firstWhere('route', 'counter.stock')['granted']);
        $this->assertSame(__('Existencias'), collect(CounterScreens::forUser($staff))->firstWhere('route', 'counter.stock')['label']);
        $routes = array_column(CounterScreens::forUser($staff), 'route');
        $this->assertSame(array_search('counter.pos', $routes, true) + 1, array_search('counter.stock', $routes, true), 'after Dispensario');
    }

    public function test_an_operator_with_neither_permission_is_refused_and_has_no_tile(): void
    {
        $nobody = User::factory()->create();
        $nobody->locations()->sync([$this->sede->id]);

        $this->assertFalse(collect(CounterScreens::forUser($nobody))->firstWhere('route', 'counter.stock')['granted']);
        $this->screen($nobody)->assertForbidden();
    }

    // --- 2–3. Rellenar and Pasar a reserva -----------------------------------------------------------------------------------

    public function test_rellenar_from_the_screen_moves_reserve_into_the_jar(): void
    {
        $batch = $this->batch($this->amnesia, 2000, 3000);
        $screen = $this->screen($this->user(Role::STAFF))->call('openBatch', $batch->id);

        $screen->call('topUpJar', '10');
        $this->assertSame([3000, 2000], $this->figures($batch));
        $this->assertSame(2, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::RESERVE_OUT->value)->count());
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'stock.topped_up')->exists());
        $screen->assertSee(__('Rellenado: :moved · bote :jar · reserva :reserve', ['moved' => '+10.00 g', 'jar' => '30.00 g', 'reserve' => '20.00 g']));

        $screen->call('topUpJar', '25'); // more than the 20 g left
        $this->assertSame([3000, 2000], $this->figures($batch));
        $screen->assertSet('flashType', 'error');

        $screen->call('topUpJar', null); // «Toda la reserva»
        $this->assertSame([5000, 0], $this->figures($batch));
    }

    public function test_pasar_a_reserva_from_the_screen_and_never_more_than_the_jar(): void
    {
        $batch = $this->batch($this->amnesia, 2000);
        $screen = $this->screen($this->user(Role::STAFF))->call('openBatch', $batch->id);

        $screen->call('moveToReserve', '5');
        $this->assertSame([1500, 500], $this->figures($batch));

        $screen->call('moveToReserve', '50');
        $this->assertSame([1500, 500], $this->figures($batch));
        $screen->assertSet('flashType', 'error');
    }

    // --- 4. Actualizar peso del bote ------------------------------------------------------------------------------------------

    public function test_staff_fix_a_jars_weight_with_one_tapped_reason(): void
    {
        $batch = $this->batch($this->amnesia, 2000);
        $staff = $this->user(Role::STAFF); // Ben (364): staff hold stock.take by default
        $screen = $this->screen($staff)->call('openBatch', $batch->id)->assertSeeHtml('data-action-weigh');

        $screen->call('updateJarWeight', '18', 'WEIGHING_ERROR');

        $adjustment = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertSame([-200, __('Error al pesar')], [$adjustment->qty_cg->centigrams, $adjustment->reason]);
        $this->assertSame([1800, 0], $this->figures($batch));
    }

    public function test_a_manager_is_not_asked_and_it_is_recorded_as_manager_approved(): void
    {
        $batch = $this->batch($this->amnesia, 2000);
        $this->screen($this->user(Role::MANAGER, '2345'))->call('openBatch', $batch->id)->assertDontSeeHtml('data-weigh-reasons')
            ->call('updateJarWeight', '19');

        $this->assertSame(ManagerApproval::reason(), StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::ADJUSTMENT->value)->sole()->reason);
    }

    public function test_without_stock_take_the_weight_fix_is_not_shown_and_a_forged_call_is_refused(): void
    {
        $this->setRolePermission(Role::STAFF, 'stock.take', false);
        $batch = $this->batch($this->amnesia, 2000);
        $screen = $this->screen($this->user(Role::STAFF))->call('openBatch', $batch->id)->assertDontSeeHtml('data-action-weigh')
            ->assertSeeHtml('data-action-top-up');

        $screen->call('updateJarWeight', '18', 'WEIGHING_ERROR')->assertSet('flashType', 'error');
        $this->assertSame([2000, 0], $this->figures($batch));
    }

    public function test_only_stock_manage_may_add_sealed_bags_to_the_reserve(): void
    {
        $batch = $this->batch($this->amnesia, 2000);
        $this->screen($this->user(Role::STAFF))->call('openBatch', $batch->id)->assertDontSeeHtml('data-action-add-reserve')
            ->call('addToReserve', '100', 'Bolsas sin dar de alta')->assertSet('flashType', 'error');
        $this->assertSame([2000, 0], $this->figures($batch));

        $this->screen($this->user(Role::MANAGER, '2345'))->call('openBatch', $batch->id)->assertSeeHtml('data-action-add-reserve')
            ->call('addToReserve', '100', 'Bolsas sin dar de alta');
        $this->assertSame([2000, 10000], $this->figures($batch));
        $movement = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertTrue((bool) $movement->on_reserve);
    }

    // --- 5. The forgotten top-up rule, now in RecountBatch too ----------------------------------------------------------------

    public function test_recount_batch_absorbs_a_forgotten_top_up_before_adjusting(): void
    {
        $manager = $this->user(Role::MANAGER, '2345');
        $absorbed = $this->batch($this->amnesia, 500, 1000);
        (new RecountBatch)->handle($absorbed, 1500, 'Recuento', $manager);

        $this->assertSame([1500, 0], $this->figures($absorbed));
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $absorbed->id)->where('type', StockMovementType::ADJUSTMENT->value)->count());
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'stock.topped_up_unrecorded')->where('auditable_id', $absorbed->id)->exists());
        $this->assertSame('Rellenado sin registrar', StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $absorbed->id)->where('type', StockMovementType::RESERVE_OUT->value)->first()->reason);

        $beyond = $this->batch($this->critical, 500, 1000);
        (new RecountBatch)->handle($beyond, 2000, 'Recuento', $manager);
        $this->assertSame([2000, 0], $this->figures($beyond));
        $this->assertSame([500], StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $beyond->id)->where('type', StockMovementType::ADJUSTMENT->value)
            ->get()->map(fn (StockMovement $m): int => $m->qty_cg->centigrams)->all());

        $short = $this->batch($this->amnesia, 2000, 1000);
        (new RecountBatch)->handle($short, 1500, 'Recuento', $manager);
        $this->assertSame([1500, 1000], $this->figures($short), 'a shortfall never touches the reserve');
    }

    // --- 6. Filters and the summary -------------------------------------------------------------------------------------------

    public function test_filters_the_summary_and_the_empty_batches(): void
    {
        $withReserve = $this->batch($this->amnesia, 2000, 30000);
        $this->batch($this->critical, 50000, 0);
        $lowJar = $this->batch($this->critical, 100, 5000);
        GeneticPrice::query()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->critical->id, 'location_id' => $this->sede->id,
            'price_per_gram_cents' => 1000, 'low_stock_threshold_cg' => 60000, 'active' => true]);
        $empty = $this->batch($this->amnesia, 0, 0);
        $elsewhere = $this->batch($this->amnesia, 0, 99900, $this->norte);

        $screen = $this->screen($this->user(Role::STAFF));
        $screen->assertSee(__('Reserva sellada en la sede: :grams en :count lotes', ['grams' => '350.00 g', 'count' => 2]));
        $this->assertSame([$withReserve->id, $lowJar->id], $screen->instance()->sheet()->rows('reserve')->pluck('id')->all());
        $this->assertContains($lowJar->id, $screen->instance()->sheet()->rows('low')->pluck('id')->all());
        $this->assertNotContains($empty->id, $screen->instance()->sheet()->rows('all')->pluck('id')->all());
        $this->assertContains($empty->id, $screen->instance()->sheet()->rows('all', showEmpty: true)->pluck('id')->all());
        $this->assertNotContains($elsewhere->id, $screen->instance()->sheet()->rows('all', showEmpty: true)->pluck('id')->all());
    }

    // --- Prompt 365 — the empties are counted and grouped; an empty reserve says why ----------------------------------------

    public function test_the_empties_are_counted_and_shown_grouped_at_the_bottom(): void
    {
        $this->batch($this->critical, 2000);
        $this->batch($this->amnesia, 0, 0);
        $this->batch($this->critical, 0, 0);
        $screen = $this->screen($this->user(Role::STAFF));

        $line = trans_choice(':count lote agotado oculto|:count lotes agotados ocultos', 2, ['count' => 2]);
        $screen->assertSee($line)->assertSeeHtml('data-stock-empty-toggle')->assertDontSeeHtml('data-stock-empty-heading');

        $html = $screen->call('toggleEmpty')->assertSee(__('Ocultar agotados'))->html();
        $heading = strpos($html, 'data-stock-empty-heading');
        $this->assertNotFalse($heading);
        $rows = [];
        preg_match_all('/data-stock-row="([^"]+)"/', $html, $rows, PREG_OFFSET_CAPTURE);
        $positions = collect($rows[1])->mapWithKeys(fn (array $m): array => [$m[0] => $m[1]]);
        $nonEmpty = Batch::query()->withoutGlobalScopes()->where('remaining_cg', '>', 0)->pluck('id');
        $empty = Batch::query()->withoutGlobalScopes()->where('remaining_cg', 0)->where('reserve_cg', 0)->pluck('id');
        $this->assertTrue($nonEmpty->every(fn (string $id): bool => $positions[$id] < $heading), 'every non-empty row comes first');
        $this->assertTrue($empty->every(fn (string $id): bool => $positions[$id] > $heading), 'the empties are grouped under «Agotados»');
    }

    public function test_with_no_empty_batch_there_is_no_line(): void
    {
        $this->batch($this->amnesia, 2000);
        $this->screen($this->user(Role::STAFF))->assertDontSeeHtml('data-stock-empty-toggle');
    }

    public function test_an_empty_reserve_says_why_and_a_failed_search_still_says_nothing_matches(): void
    {
        $this->batch($this->amnesia, 2000);
        $screen = $this->screen($this->user(Role::STAFF));

        $screen->call('setFilter', 'reserve')->assertSee(__('No hay reserva sellada apuntada en esta sede.'))
            ->assertSee(__('Se apunta al recibir un lote («De ello, en reserva»), con «Pasar a reserva» aquí, o en Inventario.'))
            ->assertDontSee(__('Nada coincide.'));

        $screen->call('setFilter', 'all')->set('batchFilter', 'zzz')->assertSee(__('Nada coincide.'))
            ->assertDontSee(__('No hay reserva sellada apuntada en esta sede.'));
    }

    // --- 8. The dispensary no longer carries the reserve controls -------------------------------------------------------------

    public function test_the_dispensary_weight_panel_has_no_reserve_controls_and_its_empty_jar_links_here(): void
    {
        $batch = $this->batch($this->amnesia, 0, 3000);
        $this->batch($this->critical, 2000);
        $staff = $this->user(Role::STAFF);
        $this->actingAs($staff);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($staff);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);

        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth()]);
        (new EnrolMembership)->handle($member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id]),
            ['actor' => $this->user(Role::OWNER, '1234'), 'fee_cents' => 0]);
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $pos->assertSeeHtml(e(route('counter.stock', ['lote' => $batch->id, 'from' => 'pos'])));
        $pos->call('chooseGenetic', $this->critical->id)->assertDontSeeHtml('data-reserve-panel');
    }

    // --- 9. Handover and training ---------------------------------------------------------------------------------------------

    public function test_during_a_handover_a_top_up_is_refused(): void
    {
        $batch = $this->batch($this->amnesia, 2000, 3000);
        $staff = $this->user(Role::STAFF);
        $this->actingAs($staff);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($staff);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        $snapshot = $this->snapshotFrom('/counter/existencias', 'counter.stock-screen');

        CounterHandover::begin($staff->id, $this->sede->id, route('counter.home'));
        $this->livewirePost($snapshot, [], [['openBatch', [$batch->id]], ['topUpJar', ['10']]])->assertForbidden();
        $this->assertSame([2000, 3000], $this->figures($batch));
    }

    public function test_the_screen_meets_the_counter_trait_contract_and_can_hand_over(): void
    {
        // IdentifiesOperator needs resolveLocation() from its host (a handover started here 500'd in the browser without it).
        $this->screen($this->user(Role::STAFF))->call('beginHandover')->assertOk();
        $this->assertTrue(CounterHandover::active());
    }

    public function test_a_top_up_in_training_mode_is_rolled_back(): void
    {
        $batch = $this->batch($this->amnesia, 2000, 3000);
        $owner = $this->user(Role::OWNER, '1234');
        $this->actingAs($owner);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        $this->post(route('counter.location'), ['location_id' => $this->sede->id])->assertRedirect();
        CounterOperator::set($owner);
        $this->post(route('counter.training.start'))->assertRedirect();
        $this->assertTrue(TrainingMode::active());

        $this->livewirePost($this->snapshotFrom('/counter/existencias', 'counter.stock-screen'), [], [['openBatch', [$batch->id]], ['topUpJar', ['10']]])->assertOk();
        $this->assertSame([2000, 3000], $this->figures($batch));
    }

    // --- 10. A forged ?lote= -------------------------------------------------------------------------------------------------

    public function test_a_lote_from_another_sede_or_org_opens_nothing(): void
    {
        $mine = $this->batch($this->amnesia, 2000, 3000);
        $theirs = $this->batch($this->amnesia, 2000, 3000, $this->norte);
        $staff = $this->user(Role::STAFF);

        $this->screen($staff, ['lote' => $mine->id])->assertSet('openBatchId', $mine->id);
        $this->screen($staff, ['lote' => $theirs->id])->assertSet('openBatchId', null);
        $this->screen($staff, ['lote' => '01jzzzzzzzzzzzzzzzzzzzzzzz'])->assertSet('openBatchId', null);
    }
}
