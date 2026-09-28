<?php

namespace Tests\Feature\Stock;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Stock\AllocateFromBatches;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Stock\SelectBatch;
use App\Actions\Stock\TransferBatch;
use App\Actions\Till\OpenTill;
use App\Enums\DashboardAlert;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;
use App\Support\Period;
use App\Support\StockCeiling;
use App\ViewModels\Dashboard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 277 (Ben's 270) — a central store for the grow, and batches moved (whole or part) between locations.
 *
 * Every batch had to belong to a sede; there was no way to hold stock that is not at a counter, and although
 * TRANSFER_IN/OUT and the `stock.transfer` permission existed, nothing performed a transfer. The store is a location
 * of its own kind (ALMACEN) — so every location filter keeps working — and a transfer moves a quantity: the whole batch
 * moves itself, a part becomes a child batch with the same lote number, traceable to the harvest.
 */
class CentralStoreTransferTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $store;

    private Location $centro;

    private Location $norte;

    private Genetic $genetic;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Norte']);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
    }

    private function harvest(Location $at, string $grams = '1000'): Batch
    {
        return (new IntakeBatch)->handle($this->genetic, $at, ['grams' => $grams, 'batch_no' => 'COSECHA-1']);
    }

    private function movements(Batch $batch): array
    {
        return StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (StockMovement $m): array => [$m->type->value, (int) $m->getRawOriginal('qty_cg'), $m->location_id])->all();
    }

    // --- 1. Intake to the store ----------------------------------------------------------------------------------

    public function test_stock_can_be_received_at_the_store_and_no_counter_can_take_from_it(): void
    {
        $batch = $this->harvest($this->store);

        $this->assertSame($this->store->id, $batch->location_id);
        $this->assertNull((new SelectBatch)->fefo($this->genetic, $this->centro), 'a sede allocated from the store');
        $this->assertNotContains($this->store->id, app(LocationSwitcher::class)->available($this->owner)->pluck('id')->all());
    }

    // --- 2. Part transfer ------------------------------------------------------------------------------------------

    public function test_part_of_a_store_batch_becomes_a_child_batch_at_the_sede(): void
    {
        $source = $this->harvest($this->store);

        $child = (new TransferBatch)->handle($source, $this->centro, 25000, $this->owner); // 250 g

        $this->assertSame(75000, $source->fresh()->remaining_cg->centigrams);
        $this->assertNotSame($source->id, $child->id);
        $this->assertSame($this->centro->id, $child->location_id);
        $this->assertSame(25000, $child->remaining_cg->centigrams);
        $this->assertSame('COSECHA-1', $child->batch_no);
        $this->assertSame($source->id, $child->parent_batch_id);

        $this->assertContains(['TRANSFER_OUT', -25000, $this->store->id], $this->movements($source));
        $this->assertContains(['TRANSFER_IN', 25000, $this->centro->id], $this->movements($child));
        $this->assertTrue((new SelectBatch)->fefo($this->genetic, $this->centro)?->is($child), 'Centro does not allocate from the child');
        $this->assertTrue(AuditLog::query()->where('action', 'stock.transferred')->exists());
    }

    // --- 3. Whole transfer -----------------------------------------------------------------------------------------

    public function test_a_whole_transfer_moves_the_batch_itself(): void
    {
        $batch = $this->harvest($this->store, '100');

        $moved = (new TransferBatch)->handle($batch, $this->centro, 10000, $this->owner);

        $this->assertSame($batch->id, $moved->id);
        $this->assertSame($this->centro->id, $moved->location_id);
        $this->assertSame(10000, $moved->remaining_cg->centigrams);
        $this->assertSame(1, Batch::query()->withoutGlobalScopes()->count());
        $movements = $this->movements($batch);
        $this->assertContains(['TRANSFER_OUT', -10000, $this->store->id], $movements);
        $this->assertContains(['TRANSFER_IN', 10000, $this->centro->id], $movements);
    }

    // --- 4. Sede to sede, and the ceilings --------------------------------------------------------------------------

    public function test_a_sede_to_sede_transfer_moves_the_ceiling_figures(): void
    {
        $batch = $this->harvest($this->centro, '40');

        (new TransferBatch)->handle($batch, $this->norte, 1500, $this->owner);

        $this->assertSame(2500, StockCeiling::forLocation($this->centro)['on_site_cg']);
        $this->assertSame(1500, StockCeiling::forLocation($this->norte)['on_site_cg']);
    }

    public function test_the_store_has_no_per_location_ceiling_but_counts_in_the_association_total(): void
    {
        $this->harvest($this->store, '5000');

        $this->assertFalse(StockCeiling::forLocation($this->store)['exceeded'], 'a memberless store read as over its ceiling');
        $association = StockCeiling::forOrganisation($this->org->id);
        $this->assertSame(500000, $association['on_site_cg']);
        $this->assertTrue($association['exceeded']);

        $rollup = new Dashboard($this->org->id, null, Period::today($this->centro), isRollup: true);
        $keys = array_column($rollup->alerts(), 'key');
        $this->assertContains(DashboardAlert::ASSOCIATION_STOCK_CEILING->value, $keys);
        $this->assertNotContains(DashboardAlert::STOCK_CEILING_EXCEEDED->value, $keys, 'the store tripped a per-sede ceiling');
    }

    // --- 5. Refusals ------------------------------------------------------------------------------------------------

    public function test_a_transfer_is_refused_when_it_should_be(): void
    {
        $batch = $this->harvest($this->store, '10');
        $elsewhere = Location::factory()->create(['organisation_id' => Organisation::factory()->create()->id]);

        foreach ([[2000, $this->centro], [0, $this->centro], [-100, $this->centro], [500, $this->store], [500, $elsewhere]] as [$qty, $to]) {
            try {
                (new TransferBatch)->handle($batch, $to, $qty, $this->owner);
                $this->fail("a transfer of {$qty} to {$to->name} was allowed");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $this->expectException(AuthorizationException::class);
        (new TransferBatch)->handle($batch, $this->centro, 500, $staff);
    }

    public function test_grams_taken_first_cannot_also_be_transferred(): void
    {
        $batch = $this->harvest($this->centro, '10');
        (new RecordStockMovement)->handle($batch, StockMovementType::DISPENSE, -600, ['reason' => 'Dispensación']);

        try {
            (new TransferBatch)->handle($batch, $this->norte, 1000, $this->owner); // the original 10 g
            $this->fail('transferred grams that were already dispensed');
        } catch (InvalidArgumentException|RuntimeException) {
            $this->assertSame(400, $batch->fresh()->remaining_cg->centigrams);
        }
    }

    // --- 7. Traceability ---------------------------------------------------------------------------------------------

    public function test_stock_drawn_from_a_child_batch_carries_the_harvests_lote_number(): void
    {
        $source = $this->harvest($this->store);
        (new TransferBatch)->handle($source, $this->centro, 1000, $this->owner);

        $plan = (new AllocateFromBatches)->handle($this->genetic, $this->centro, 300);

        $this->assertSame('COSECHA-1', $plan[0]['batch']->batch_no);
        $this->assertSame($source->id, $plan[0]['batch']->parent_batch_id);
    }

    // --- 8. The store never behaves like a counter --------------------------------------------------------------------

    public function test_the_store_cannot_open_a_till_or_hold_members(): void
    {
        try {
            (new OpenTill)->handle($this->store, 'POS-1', 1000);
            $this->fail('a till opened at the store');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->expectException(RuntimeException::class);
        (new EnrolMembership)->handle($member, $this->store, MembershipTier::factory()->create(['organisation_id' => $this->org->id]));
    }

    public function test_the_counter_refuses_the_store_as_its_sede(): void
    {
        $this->post(route('counter.location'), ['location_id' => $this->store->id]);

        $this->assertNotSame($this->store->id, session('counter.location_id'));
    }
}
