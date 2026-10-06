<?php

namespace Tests\Feature\Stock;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\MoveToReserve;
use App\Actions\Stock\RecordStockCountLine;
use App\Actions\Stock\SelectBatch;
use App\Actions\Stock\StartStockCount;
use App\Actions\Stock\TopUpFromReserve;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\Support\StockCover;
use App\Support\ZReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 359 — the club: "they don't keep all the stock at the club in the jars — they have top-ups in sealed bags. They
 * don't account for it, so it mucks up the stock check." A batch holds TWO figures at its sede: the JAR (`remaining_cg`,
 * what is dispensed and what the end-of-day reweigh expects) and the sealed RESERVE (`reserve_cg`). «Rellenar» moves
 * reserve → jar; the count never eats the bags; a forgotten Rellenar is absorbed, not adjusted.
 */
class SealedReserveTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia', 'product_type' => ProductType::FLOWER]);
    }

    /** A batch with $jar in the jar and $reserve sealed, at €10/g. */
    private function batch(int $jar, int $reserve, ?Genetic $genetic = null): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => ($genetic ?? $this->genetic)->id, 'location_id' => $this->sede->id,
            'initial_cg' => $jar + $reserve, 'remaining_cg' => $jar, 'reserve_cg' => $reserve, 'status' => BatchStatus::OPEN,
            'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
    }

    /** The end-of-day reweigh (TillSession's blind count) of one batch. */
    private function reweigh(Batch $batch, int $countedCg): StockTake
    {
        $take = StockTake::create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'opened_by' => $this->owner->id,
            'opened_at' => now(), 'status' => StockTakeStatus::OPEN]);

        return (new CommitStockTake)->handle($take, [['type' => 'batch', 'id' => $batch->id, 'counted' => $countedCg]], $this->owner);
    }

    private function adjustments(Batch $batch): array
    {
        return StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::ADJUSTMENT->value)
            ->get()->map(fn (StockMovement $m): array => [$m->qty_cg->centigrams, (bool) $m->on_reserve])->all();
    }

    // --- 1. The count no longer eats sealed stock ------------------------------------------------------------------------------

    public function test_counting_the_jar_leaves_the_sealed_bags_alone(): void
    {
        $batch = $this->batch(5000, 45000);
        $this->reweigh($batch, 5000);

        $this->assertSame([], $this->adjustments($batch));
        $this->assertSame([5000, 45000], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
    }

    // --- 2–3. Rellenar and Pasar a reserva ----------------------------------------------------------------------------------------

    public function test_rellenar_moves_reserve_into_the_jar_with_two_movements_and_an_audit(): void
    {
        $batch = $this->batch(500, 3000);
        (new TopUpFromReserve)->handle($batch, 1000, $this->owner);

        $this->assertSame([1500, 2000], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
        $moves = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::RESERVE_OUT->value)->get();
        $this->assertSame([[-1000, true], [1000, false]], $moves->sortBy('qty_cg')->map(fn (StockMovement $m): array => [$m->qty_cg->centigrams, (bool) $m->on_reserve])->values()->all());
        $this->assertSame(1, AuditLog::query()->where('action', 'stock.topped_up')->count());

        (new TopUpFromReserve)->handle($batch->fresh(), null, $this->owner); // «Toda la reserva»
        $this->assertSame([3500, 0], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);

        foreach ([0, -100, 1] as $bad) { // 1 cg is more than the (now empty) reserve
            try {
                (new TopUpFromReserve)->handle($batch->fresh(), $bad, $this->owner);
                $this->fail("Rellenar {$bad} cg was accepted");
            } catch (RuntimeException) {
            }
        }
    }

    public function test_pasar_a_reserva_moves_the_jar_into_the_reserve_and_never_more_than_the_jar(): void
    {
        $batch = $this->batch(3000, 0);
        (new MoveToReserve)->handle($batch, 2000, $this->owner);

        $this->assertSame([1000, 2000], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
        $this->assertSame(1, AuditLog::query()->where('action', 'stock.reserved')->count());
        $this->expectException(RuntimeException::class);
        (new MoveToReserve)->handle($batch->fresh(), 1001, $this->owner);
    }

    // --- 4, 10. The counter ---------------------------------------------------------------------------------------------------------

    private function counter(): Testable
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth()]);
        (new EnrolMembership)->handle($member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id]), ['actor' => $this->owner, 'fee_cents' => 0]);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);

        return Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
    }

    public function test_an_empty_jar_with_a_reserve_says_rellenar_not_sin_lote_and_fefo_never_draws_the_reserve(): void
    {
        $batch = $this->batch(0, 3000);

        $this->assertNull((new SelectBatch)->fefo($this->genetic, $this->sede), 'FEFO never allocates from the reserve');
        $pos = $this->counter()
            ->assertSee(__('Bote vacío — :grams en reserva · Rellenar', ['grams' => '30.00 g']))
            ->assertSeeHtml('data-reserve="3000"')->assertSeeHtml('data-reserve-filter');

        $pos->call('chooseGenetic', $this->genetic->id)->assertSeeHtml('data-top-up')->call('topUpJar', null);
        $this->assertSame([3000, 0], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
    }

    public function test_a_low_jar_with_a_reserve_is_a_prompt_to_top_up_and_low_stock_reads_jar_plus_reserve(): void
    {
        $this->batch(100, 50000);

        $this->counter()->assertSee(__('Bote bajo, hay reserva'))->assertSee(__('Reserva: :grams', ['grams' => '500.00 g']));
        $this->assertSame(50100, StockCover::onHandCgFor([$this->genetic], (string) $this->sede->id)[$this->genetic->id]);
    }

    // --- 5–6. A forgotten Rellenar; a real shortfall --------------------------------------------------------------------------------

    public function test_a_forgotten_rellenar_is_absorbed_not_adjusted_and_the_manager_sees_it(): void
    {
        $batch = $this->batch(500, 1000);
        $session = (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        $take = $this->reweigh($batch, 1500); // the bag was opened into the jar, nobody tapped Rellenar
        // The manager sees it on the till's report — never a question to staff.
        $this->assertSame(1000, ZReport::forMany(collect([$session->fresh()]))[$session->id]['unrecorded_topup_cg']);

        $this->assertSame([], $this->adjustments($batch), 'no stock was created');
        $this->assertSame([1500, 0], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
        $this->assertSame(1000, (int) $take->lines()->sole()->unrecorded_topup_cg?->centigrams);

        $other = $this->batch(500, 1000, Genetic::factory()->create(['organisation_id' => $this->org->id]));
        $this->reweigh($other, 2000); // 10 g absorbed + 5 g found
        $this->assertSame([[500, false]], $this->adjustments($other));
        $this->assertSame([2000, 0], [$other->fresh()->remaining_cg->centigrams, $other->fresh()->reserve_cg->centigrams]);
    }

    public function test_a_shortfall_is_a_shortfall_and_never_touches_the_reserve(): void
    {
        $batch = $this->batch(5000, 1000);
        $this->reweigh($batch, 4500);

        $this->assertSame([[-500, false]], $this->adjustments($batch));
        $this->assertSame(1000, $batch->fresh()->reserve_cg->centigrams);
    }

    // --- 7–9. The ceiling, intake, the full inventory ----------------------------------------------------------------------------------

    public function test_the_ceiling_counts_the_reserve(): void
    {
        $this->batch(5000, 45000);

        $this->assertSame(50000, StockCeiling::forLocation($this->sede)['on_site_cg']);
    }

    public function test_intake_splits_the_total_into_jar_and_reserve_with_one_intake(): void
    {
        $batch = (new IntakeBatch)->handle($this->genetic, $this->sede, ['grams' => 500, 'reserve_grams' => 450, 'price_per_gram_cents' => 1000, 'operator_id' => $this->owner->id]);

        $this->assertSame([5000, 45000], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
        $this->assertSame(1, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::INTAKE->value)->count());
        $this->assertSame(50000, (int) StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::INTAKE->value)->sum('qty_cg'));
    }

    public function test_the_full_inventory_counts_the_reserve_on_the_same_row(): void
    {
        // Prompt 360 replaced 359's separate «Reserva sellada» line with a second figure on the batch's own row.
        $batch = $this->batch(5000, 45000);
        $take = (new StartStockCount)->handle($this->sede, $this->owner);
        $line = $take->lines()->where('countable_id', $batch->id)->sole();

        (new RecordStockCountLine)->handle($line, '50', $this->owner, reserve: '440'); // 10 g short in the bags
        (new CommitStockTake)->applyCount($take->fresh(), $this->owner, [$line->id => ['reason' => 'RECORDING_ERROR', 'note' => 'Bolsa abierta']]);

        $this->assertSame([[-1000, true]], $this->adjustments($batch));
        $this->assertSame([5000, 44000], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
    }

    // --- 11. The reweigh screen is unchanged -----------------------------------------------------------------------------------------

    public function test_the_end_of_day_reweigh_asks_nothing_about_bags(): void
    {
        $untouched = $this->batch(5000, 45000); // initial = jar + reserve, nothing sold
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        $till = Livewire::test(TillSession::class)->instance();
        $this->assertSame([], $till->reweighBatches()->pluck('id')->all(), 'sealed bags alone add no line to the evening count');
        (new TopUpFromReserve)->handle($untouched, 1000, $this->owner); // a move WITHIN the batch is not a sale either
        $this->assertSame([], $till->reweighBatches()->pluck('id')->all());

        $view = (string) file_get_contents(resource_path('views/livewire/counter/till-session.blade.php'));
        $reweigh = substr($view, (int) strpos($view, 'submitReweigh'), 6000);

        $this->assertStringNotContainsString('reserva', mb_strtolower($reweigh));
        $this->assertStringNotContainsString('reserve', mb_strtolower($reweigh));
    }
}
