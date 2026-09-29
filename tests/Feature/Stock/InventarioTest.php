<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\CancelStockCount;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Stock\RecordStockCountLine;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Stock\StartStockCount;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockCountReason;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Filament\Pages\Inventario;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 318 — *Inventario*: a full stock count for a sede in one sitting (or several), reviewed, then applied. It
 * reuses `stock_takes`/`stock_take_lines` and `CommitStockTake` (no second count model, no second adjustment path). The
 * one rule that matters: a line's difference is taken against what the system held WHEN THAT LINE WAS COUNTED, so a sale
 * between counting and applying is never counted twice.
 */
class InventarioTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

    private Genetic $amnesia;

    private Batch $batch;

    private Article $agua;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($this->owner);
        $this->amnesia = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        $this->batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->amnesia->id, 'location_id' => $this->centro->id, 'remaining_cg' => 10000, 'price_per_gram_cents' => 1000]);
        $this->agua = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Agua', 'stock' => 20, 'active' => true, 'price_cents' => 150]);
    }

    private function start(?Location $at = null): StockTake
    {
        return (new StartStockCount)->handle($at ?? $this->centro, $this->owner);
    }

    private function line(StockTake $take, Batch|Article $item): StockTakeLine
    {
        return $take->lines()->where('countable_id', $item->id)->sole();
    }

    // --- 1–3. Starting and counting --------------------------------------------------------------------------------------

    public function test_a_count_covers_every_batch_with_stock_and_every_active_product_at_the_sede_and_opens_once(): void
    {
        $empty = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->amnesia->id, 'location_id' => $this->centro->id, 'remaining_cg' => 0]);
        $elsewhere = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->amnesia->id, 'location_id' => $this->norte->id, 'remaining_cg' => 5000]);
        $inactive = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'active' => false, 'stock' => 5]);

        $take = $this->start();

        $this->assertEqualsCanonicalizing([$this->batch->id, $this->agua->id], $take->lines()->pluck('countable_id')->all());
        $this->assertNotContains($empty->id, $take->lines()->pluck('countable_id')->all());
        $this->assertNotContains($elsewhere->id, $take->lines()->pluck('countable_id')->all());
        $this->assertNotContains($inactive->id, $take->lines()->pluck('countable_id')->all());
        $this->assertSame($take->id, $this->start()->id, 'a second start opened another count');
    }

    public function test_a_store_count_has_no_products(): void
    {
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN]);
        $stored = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->amnesia->id, 'location_id' => $store->id, 'remaining_cg' => 80000]);

        $this->assertSame([$stored->id], $this->start($store)->lines()->pluck('countable_id')->all());
    }

    public function test_counting_is_blind_by_default(): void
    {
        $take = $this->start();

        $this->batch->forceFill(['initial_cg' => 12345])->saveQuietly(); // the intake figure is a quantity too
        Livewire::test(Inventario::class, ['count' => $take->id])->assertSeeHtml('data-count-line')->assertDontSee('100.00 g')->assertDontSee('123.45 g');

        Settings::set('stock_count_show_expected', true, SettingType::BOOL);
        Livewire::test(Inventario::class, ['count' => $take->id])->assertSee('100.00 g');
    }

    public function test_saving_a_line_keeps_who_when_and_the_locked_current_quantity(): void
    {
        $take = $this->start();
        $this->travelTo(now()->setTime(18, 30));

        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '98', $this->owner);

        $line = $this->line($take, $this->batch)->fresh();
        $this->assertSame(9800, $line->counted_cg->centigrams);
        $this->assertSame(10000, $line->expected_cg->centigrams);
        $this->assertSame($this->owner->id, $line->counted_by);
        $this->assertTrue($line->counted_at->equalTo(now()));
    }

    // --- 4–5. The maths --------------------------------------------------------------------------------------------------------

    public function test_a_sale_between_counting_and_applying_is_not_counted_twice(): void
    {
        $take = $this->start();
        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '98', $this->owner);   // 100 g counted at 98 g
        (new RecordStockCountLine)->handle($this->line($take, $this->agua), '20', $this->owner);
        (new RecordStockMovement)->handle($this->batch->fresh(), StockMovementType::DISPENSE, -500); // then 5 g sold

        (new CommitStockTake)->applyCount($take, $this->owner, []);

        $adjustment = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $this->batch->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertSame(-200, $adjustment->qty_cg->centigrams, 'the difference was taken against the wrong moment');
        $this->assertSame(9300, $this->batch->fresh()->remaining_cg->centigrams);
    }

    public function test_recounting_a_line_replaces_its_count_and_its_snapshot(): void
    {
        $take = $this->start();
        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '98', $this->owner);
        (new RecordStockMovement)->handle($this->batch->fresh(), StockMovementType::DISPENSE, -500);

        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '94', $this->owner);

        $line = $this->line($take, $this->batch)->fresh();
        $this->assertSame([9400, 9500], [$line->counted_cg->centigrams, $line->expected_cg->centigrams]);
    }

    // --- 6–7. Review, apply, cancel ----------------------------------------------------------------------------------------------

    public function test_applying_needs_every_line_counted_and_a_reason_above_the_tolerance(): void
    {
        $take = $this->start();
        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '90', $this->owner); // −10 g of 100 g: above 5 % / 2 g

        try {
            (new CommitStockTake)->applyCount($take, $this->owner, []);
            $this->fail('applied with a line still uncounted');
        } catch (DomainException) {
        }

        (new RecordStockCountLine)->handle($this->line($take, $this->agua), null, $this->owner, notCountedReason: 'Caja sin abrir');
        try {
            (new CommitStockTake)->applyCount($take, $this->owner, []);
            $this->fail('applied a large difference without a reason');
        } catch (DomainException) {
        }
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::ADJUSTMENT->value)->count());

        $batchLine = $this->line($take, $this->batch);
        (new CommitStockTake)->applyCount($take, $this->owner, [$batchLine->id => ['reason' => StockCountReason::MERMA->value, 'note' => 'Secado']]);

        $adjustment = StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertSame(-1000, $adjustment->qty_cg->centigrams);
        $this->assertStringContainsString(StockCountReason::MERMA->label(), (string) $adjustment->reason);
        $this->assertSame(20, $this->agua->fresh()->stock, 'a not-counted line touched the ledger');
        $this->assertSame(StockTakeStatus::COMMITTED, $take->fresh()->status);
        $this->assertSame($this->owner->id, $take->fresh()->committed_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'stock_take.committed')->count());
    }

    public function test_cancelling_touches_nothing_in_the_ledger(): void
    {
        $take = $this->start();
        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '50', $this->owner);

        (new CancelStockCount)->handle($take, $this->owner);

        $this->assertSame(StockTakeStatus::CANCELLED, $take->fresh()->status);
        $this->assertSame(10000, $this->batch->fresh()->remaining_cg->centigrams);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::ADJUSTMENT->value)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'stock_take.cancelled')->count());
        $this->assertNotSame($take->id, $this->start()->id, 'a cancelled count was reopened');
    }

    // --- 8–9. The report and permissions ----------------------------------------------------------------------------------------

    public function test_the_report_lists_every_line_and_the_totals(): void
    {
        $take = $this->start();
        (new RecordStockCountLine)->handle($this->line($take, $this->batch), '99', $this->owner);
        (new RecordStockCountLine)->handle($this->line($take, $this->agua), '18', $this->owner);
        (new CommitStockTake)->applyCount($take, $this->owner, []);

        $html = Inventario::reportView($take->fresh())->render();

        $this->assertStringContainsString('Amnesia', $html);
        $this->assertStringContainsString('Agua', $html);
        $this->assertStringContainsString('Sede Centro', $html);
        $this->assertStringContainsString('-1.00 g', $html);
        $this->assertStringContainsString('-2', $html);
        $this->assertStringContainsString('data-count-totals', $html);
        $this->assertNotEmpty(Livewire::test(Inventario::class)->call('downloadReport', $take->id)->effects['download'] ?? null);
    }

    public function test_only_people_with_the_count_permissions_reach_it_and_a_manager_only_their_sedes(): void
    {
        $this->setRolePermission(Role::STAFF, 'panel.access', true);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // no stock.take, no panel.stock_count
        $staff->locations()->sync([$this->centro->id]);
        $this->actingAs($staff)->get(Inventario::getUrl())->assertForbidden();

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->centro->id]);
        $this->actingAs($manager->fresh());
        $this->get(Inventario::getUrl())->assertOk();
        $this->assertSame([$this->centro->id], array_keys(Livewire::test(Inventario::class)->instance()->sedeOptions()));

        $northCount = (new StartStockCount)->handle($this->norte, $this->owner);
        $this->actingAs($manager->fresh());
        Livewire::test(Inventario::class, ['count' => $northCount->id])->assertForbidden();
    }

    // --- 10. The page end to end ------------------------------------------------------------------------------------------------

    public function test_the_page_saves_a_count_reviews_it_and_applies_it(): void
    {
        $take = $this->start();
        $batchLine = $this->line($take, $this->batch);
        $aguaLine = $this->line($take, $this->agua);

        Livewire::test(Inventario::class, ['count' => $take->id])
            ->set("entries.{$batchLine->id}", '98,5')->call('saveLine', $batchLine->id)->assertHasNoErrors()
            ->set("entries.{$aguaLine->id}", '1.5')->call('saveLine', $aguaLine->id)->assertHasErrors("entries.{$aguaLine->id}")
            ->set("notCounted.{$aguaLine->id}", 'Caja precintada')->call('markNotCounted', $aguaLine->id)
            ->assertSee(__('Contado :qty · :name · :time', ['qty' => '98.50 g', 'name' => $this->owner->name, 'time' => local_datetime(now(), 'd/m H:i', $this->centro)]));

        $this->assertSame(9850, $batchLine->fresh()->counted_cg->centigrams);
        $this->assertTrue($aguaLine->fresh()->not_counted);

        Livewire::test(Inventario::class, ['count' => $take->id, 'mode' => 'review'])
            ->assertSeeHtml('data-count-totals')->assertSeeHtml('data-count-difference="'.$batchLine->id.'"')->assertSee('-1.50 g')
            ->callAction('apply');

        $this->assertSame(StockTakeStatus::COMMITTED, $take->fresh()->status);
        $this->assertSame(9850, $this->batch->fresh()->remaining_cg->centigrams, '€ and grams: 98,5 typed → 9850 cg stored');
    }

    public function test_a_reason_typed_in_the_review_is_kept_and_applied(): void
    {
        $take = $this->start();
        $batchLine = $this->line($take, $this->batch);
        (new RecordStockCountLine)->handle($batchLine, '80', $this->owner);
        (new RecordStockCountLine)->handle($this->line($take, $this->agua), '20', $this->owner);

        $page = Livewire::test(Inventario::class, ['count' => $take->id, 'mode' => 'review'])->assertSeeHtml('data-count-needs-reason')->callAction('apply');
        $this->assertSame(StockTakeStatus::OPEN, $take->fresh()->status, 'applied a −20 g difference without a reason');

        $page->set("reasons.{$batchLine->id}.reason", StockCountReason::THEFT_OR_LOSS->value)->set("reasons.{$batchLine->id}.note", 'Bote vacío en la vitrina');
        $this->assertSame(StockCountReason::THEFT_OR_LOSS, $batchLine->fresh()->adjustment_reason, 'the reason was not kept on the line');

        Livewire::test(Inventario::class, ['count' => $take->id, 'mode' => 'review'])->callAction('apply');
        $movement = StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertSame(-2000, $movement->qty_cg->centigrams);
        $this->assertStringContainsString('Bote vacío en la vitrina', (string) $movement->reason);
    }

    public function test_the_list_shows_counts_but_not_the_tills_recounts(): void
    {
        $take = $this->start();
        StockTake::query()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'opened_by' => $this->owner->id, 'opened_at' => now(), 'status' => StockTakeStatus::COMMITTED]);

        $this->assertSame([$take->id], collect(Livewire::test(Inventario::class)->instance()->counts())->pluck('take.id')->all());
        $this->assertSame(StockTake::KIND_TILL_RECOUNT, StockTake::query()->where('id', '!=', $take->id)->sole()->fresh()->kind);
    }
}
