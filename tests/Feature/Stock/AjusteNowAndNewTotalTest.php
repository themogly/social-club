<?php

namespace Tests\Feature\Stock;

use App\Actions\Roles\SetRolePermission;
use App\Actions\Stock\RecordStockMovement;
use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ManagerApproval;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 368 §3 — the batch's *Ajuste* asked for ONE signed figure («Usa un valor negativo para restar»), which an iPhone's
 * decimal keypad cannot type (it has no minus key). Ben: "Better just to add a new value — current total and an option to
 * add or take off underneath". «Ahora», then Nuevo total / Añadir / Quitar, the amount always positive, a live preview, and
 * the difference computed against the locked figure.
 */
class AjusteNowAndNewTotalTest extends TestCase
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
        // A manager holds reasons.optional (356); these tests pick a reason, as a club that revoked it would.
        (new SetRolePermission)->handle(Role::MANAGER, 'reasons.optional', false, $this->owner);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $this->actingAs($manager);
    }

    private function batch(int $jar = 280, int $reserve = 0): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'initial_cg' => $jar + $reserve, 'remaining_cg' => $jar, 'reserve_cg' => $reserve, 'status' => BatchStatus::OPEN,
            'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
    }

    /** @param  array<string, mixed>  $data */
    private function adjust(Batch $batch, array $data): void
    {
        Livewire::test(ListBatches::class)->callTableAction('adjust', $batch, $data + ['bucket' => 'jar', 'reason_pick' => 'weighing'])
            ->assertHasNoTableActionErrors();
    }

    /** @return list<int> the ADJUSTMENT movements, in order */
    private function adjustments(Batch $batch): array
    {
        return StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::ADJUSTMENT->value)
            ->orderBy('id')->get()->map(fn (StockMovement $m): int => $m->qty_cg->centigrams)->all();
    }

    public function test_the_form_shows_now_and_the_three_ways_and_no_longer_asks_for_a_negative(): void
    {
        $batch = $this->batch(280);

        Livewire::test(ListBatches::class)->mountTableAction('adjust', $batch)
            ->assertMountedActionModalSee(['Ahora: bote 2.80 g', 'Nuevo total', 'Añadir', 'Quitar'])
            ->assertMountedActionModalDontSee('Usa un valor negativo para restar.')
            ->setTableActionData(['amount' => '6.80'])
            ->assertMountedActionModalSee('2.80 g → 6.80 g (+4.00 g)')
            ->setTableActionData(['mode' => 'remove', 'amount' => '5'])
            ->assertMountedActionModalSee('No puede quedar por debajo de 0.');
    }

    public function test_new_total_add_and_take_off_record_the_right_adjustment(): void
    {
        $batch = $this->batch(280);

        $this->adjust($batch, ['mode' => 'total', 'amount' => '6.80']);
        $this->assertSame(680, $batch->fresh()->remaining_cg->centigrams);

        $this->adjust($batch, ['mode' => 'add', 'amount' => '1.5']);
        $this->assertSame(830, $batch->fresh()->remaining_cg->centigrams);

        $this->adjust($batch, ['mode' => 'remove', 'amount' => '0.3']);
        $this->assertSame(800, $batch->fresh()->remaining_cg->centigrams);

        $this->assertSame([400, 150, -30], $this->adjustments($batch));
        $this->assertSame(__('Error al pesar'), StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->latest('id')->first()->reason);
    }

    public function test_taking_off_more_than_there_is_is_refused(): void
    {
        $batch = $this->batch(280);
        Livewire::test(ListBatches::class)->callTableAction('adjust', $batch, ['bucket' => 'jar', 'mode' => 'remove', 'amount' => '5', 'reason_pick' => 'weighing']);

        $this->assertSame(280, $batch->fresh()->remaining_cg->centigrams);
        $this->assertSame([], $this->adjustments($batch));
    }

    public function test_the_amount_takes_no_minus_sign_and_new_total_still_reduces(): void
    {
        $batch = $this->batch(280);
        Livewire::test(ListBatches::class)->callTableAction('adjust', $batch, ['bucket' => 'jar', 'mode' => 'add', 'amount' => '-1', 'reason_pick' => 'weighing'])
            ->assertHasTableActionErrors(['amount']);
        $this->assertSame(280, $batch->fresh()->remaining_cg->centigrams);

        $this->adjust($batch, ['mode' => 'total', 'amount' => '2.5']);
        $this->assertSame([-30], $this->adjustments($batch));
    }

    public function test_the_reserve_works_the_same_way(): void
    {
        $batch = $this->batch(280, 3000);
        $this->adjust($batch, ['bucket' => 'reserve', 'mode' => 'total', 'amount' => '25']);
        $this->adjust($batch, ['bucket' => 'reserve', 'mode' => 'add', 'amount' => '10']);

        $fresh = $batch->fresh();
        $this->assertSame([280, 3500], [$fresh->remaining_cg->centigrams, $fresh->reserve_cg->centigrams]);
        $this->assertSame([-500, 1000], $this->adjustments($batch));
        $this->assertTrue((bool) StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->latest('id')->first()->on_reserve);
    }

    public function test_a_sale_while_the_form_is_open_is_not_counted_twice(): void
    {
        $batch = $this->batch(1000);
        $form = Livewire::test(ListBatches::class)->mountTableAction('adjust', $batch)
            ->setTableActionData(['bucket' => 'jar', 'mode' => 'total', 'amount' => '6', 'reason_pick' => 'count']);

        // 2 g dispensed while the manager is typing: the new total is still 6.00 g, so the adjustment is −2.00 g, not −4.00 g.
        (new RecordStockMovement)->handle($batch->fresh(), StockMovementType::SALE, -200, ['reason' => 'venta de prueba']);
        $form->callMountedTableAction()->assertHasNoTableActionErrors();

        $this->assertSame(600, $batch->fresh()->remaining_cg->centigrams);
        $this->assertSame([-200], $this->adjustments($batch));
    }

    public function test_a_reasons_optional_holder_is_not_asked(): void
    {
        (new SetRolePermission)->handle(Role::MANAGER, 'reasons.optional', true, $this->owner);
        $batch = $this->batch(280);

        Livewire::test(ListBatches::class)->callTableAction('adjust', $batch, ['bucket' => 'jar', 'mode' => 'add', 'amount' => '1'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(ManagerApproval::reason(), StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->sole()->reason);
    }
}
