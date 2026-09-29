<?php

namespace Tests\Feature\Stock;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\RenameBatchLote;
use App\Actions\Till\OpenTill;
use App\Enums\LocationKind;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exceptions\StockCeilingExceededException;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\SplitQuantity;
use App\ViewModels\BatchRecall;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 303 — a new batch can be split across locations when it is created: one lote (one `batch_no`, one `lote_seq`),
 * one batch per sede with its own quantity and INTAKE, no parent (nothing was transferred), all or nothing.
 */
class SplitBatchIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $store;

    private Location $norte;

    private User $owner;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Storage house', 'kind' => LocationKind::ALMACEN]);
        app(ActiveScope::class)->setLocation(null);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
    }

    /** @return list<Batch> */
    private function parts(): array
    {
        return Batch::query()->withoutGlobalScopes()->where('genetic_id', $this->genetic->id)->orderBy('location_id')->get()->all();
    }

    // --- 1. Creating a split -----------------------------------------------------------------------------------------

    public function test_a_split_makes_one_lote_with_a_part_at_each_sede(): void
    {
        Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $this->genetic->product_type->value, 'genetic_id' => $this->genetic->id, 'label' => 'Cosecha otoño', 'sale_price_eur' => '10'])
            ->set('data.location_id', [$this->centro->id, $this->store->id])
            ->fillForm(['grams_at' => [$this->centro->id => '500', $this->store->id => '500']])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('Lote creado en :count sedes', ['count' => 2]));

        $parts = collect($this->parts());
        $this->assertCount(2, $parts);
        $this->assertEqualsCanonicalizing([$this->centro->id, $this->store->id], $parts->pluck('location_id')->all());
        $this->assertSame(1, $parts->pluck('batch_no')->unique()->count());
        $this->assertSame([1], $parts->pluck('lote_seq')->unique()->values()->all());
        $this->assertSame(['Cosecha otoño'], $parts->pluck('label')->unique()->values()->all());
        $this->assertSame([1000], $parts->pluck('price_per_gram_cents')->unique()->values()->all());
        $this->assertSame([50000, 50000], $parts->map(fn (Batch $b): int => $b->remaining_cg->centigrams)->all());
        $this->assertSame([null], $parts->pluck('parent_batch_id')->unique()->values()->all(), 'a part of a split is not a transfer');
        foreach ($parts as $part) {
            $movement = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $part->id)->sole();
            $this->assertSame([StockMovementType::INTAKE, 50000, $part->location_id], [$movement->type, $movement->qty_cg->centigrams, $movement->location_id]);
        }
        $this->assertSame(1, AuditLog::query()->where('action', 'batch.intake')->count());
    }

    // --- 2. Split equally ------------------------------------------------------------------------------------------------

    public function test_splitting_equally_never_loses_a_centigram_and_fills_the_boxes(): void
    {
        $this->assertSame([33335, 33333, 33333], SplitQuantity::evenly(100001, 3));
        $this->assertSame(100001, array_sum(SplitQuantity::evenly(100001, 3)));
        $this->assertSame([4, 3, 3], SplitQuantity::evenly(10, 3));

        $page = Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $this->genetic->product_type->value, 'genetic_id' => $this->genetic->id])
            ->set('data.location_id', [$this->centro->id, $this->norte->id, $this->store->id])
            ->fillForm(['grams' => '1000.01'])
            ->call('splitEqually');
        $boxes = array_map('strval', (array) $page->get('data.grams_at'));
        $this->assertSame(['333.35', '333.33', '333.33'], array_values($boxes));
    }

    // --- 3. The own lote number --------------------------------------------------------------------------------------

    public function test_an_own_number_names_the_whole_split_and_a_later_separate_intake_is_still_refused(): void
    {
        $parts = (new IntakeBatch)->handleParts($this->genetic, [
            ['location' => $this->centro, 'grams' => '300'], ['location' => $this->store, 'grams' => '200'],
        ], ['batch_no' => 'GROW-17']);

        $this->assertSame(['GROW-17', 'GROW-17'], $parts->pluck('batch_no')->all());

        $this->expectException(DomainException::class);
        (new IntakeBatch)->handle($this->genetic, $this->norte, ['grams' => '10', 'batch_no' => 'GROW-17']);
    }

    // --- 4. The ceiling --------------------------------------------------------------------------------------------------

    public function test_one_sede_over_a_block_ceiling_refuses_the_whole_split_and_one_override_covers_it(): void
    {
        $matrix = Settings::get('enforcement', Settings::DEFAULTS['enforcement']);
        $matrix['stock']['ceiling'] = 'BLOCK';
        Settings::set('enforcement', $matrix);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE]);
        // Centro's ceiling is 17,50 g (one member); the store has none.
        $split = [['location' => $this->centro, 'grams' => '50'], ['location' => $this->store, 'grams' => '500']];

        try {
            (new IntakeBatch)->handleParts($this->genetic, $split, []);
            $this->fail('a split over a BLOCK ceiling was created');
        } catch (StockCeilingExceededException) {
        }
        $this->assertSame([], $this->parts(), 'part of a refused split was created');

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $parts = (new IntakeBatch)->handleParts($this->genetic, $split, ['override' => true, 'override_by' => $manager, 'override_reason' => 'Cosecha estacional']);

        $this->assertCount(2, $parts);
        $override = AuditLog::query()->where('action', 'stock.ceiling.overridden')->sole();
        $this->assertSame([$this->centro->id], array_column((array) $override->after['breaches'], 'location_id'));
    }

    // --- 5. A single sede is unchanged ---------------------------------------------------------------------------------

    public function test_a_single_sede_create_is_exactly_as_before(): void
    {
        Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $this->genetic->product_type->value, 'genetic_id' => $this->genetic->id, 'sale_price_eur' => '10', 'grams' => '250'])
            ->set('data.location_id', [$this->centro->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = Batch::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$this->centro->id, 25000, null], [$batch->location_id, $batch->remaining_cg->centigrams, $batch->parent_batch_id]);
        $audit = AuditLog::query()->where('action', 'batch.intake')->sole();
        $this->assertSame(25000, $audit->after['initial_cg']);
        $this->assertArrayNotHasKey('parts', $audit->after);
    }

    // --- 6–7. The parts are one lote ----------------------------------------------------------------------------------

    public function test_a_recall_on_the_lote_lists_members_served_from_every_part(): void
    {
        $parts = (new IntakeBatch)->handleParts($this->genetic, [
            ['location' => $this->centro, 'grams' => '100'], ['location' => $this->norte, 'grams' => '100'],
        ], ['price_per_gram_cents' => 900]);
        foreach ([$this->centro, $this->norte] as $i => $sede) {
            $this->owner->locations()->syncWithoutDetaching([$sede->id]);
            $till = (new OpenTill)->handle($sede, 'POS-'.$i, 1000);
            $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Socio'.$i,
                'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
            Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $sede->id,
                'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);
            (new CommitDispensation)->handle($member, $sede, [['genetic_id' => $this->genetic->id, 'batch_id' => $parts[$i]->id, 'grams_cg' => 100]],
                ['operator_id' => $this->owner->id, 'till_session_id' => $till->id, 'cash_cents' => 900, 'wallet_cents' => 0]);
        }

        $rows = (new BatchRecall($parts[0]->fresh()))->rows();

        $this->assertEqualsCanonicalizing(['Socio0', 'Socio1'], array_map(fn (array $r): string => explode(' ', $r['socio'])[0], $rows));
    }

    public function test_renaming_the_lote_renames_every_part(): void
    {
        $parts = (new IntakeBatch)->handleParts($this->genetic, [
            ['location' => $this->centro, 'grams' => '100'], ['location' => $this->store, 'grams' => '100'],
        ], []);

        (new RenameBatchLote)->handle($parts[0], 'Cosecha invierno');

        $this->assertSame(['Cosecha invierno', 'Cosecha invierno'], collect($this->parts())->pluck('label')->all());
    }

    // --- 8. Validation ---------------------------------------------------------------------------------------------------

    public function test_a_zero_part_is_refused_in_spanish_and_a_unit_product_splits_in_whole_units(): void
    {
        app()->setLocale('es');
        Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $this->genetic->product_type->value, 'genetic_id' => $this->genetic->id, 'sale_price_eur' => '10'])
            ->set('data.location_id', [$this->centro->id, $this->store->id])
            ->fillForm(['grams_at' => [$this->centro->id => '500', $this->store->id => '0']])
            ->call('create')
            ->assertHasFormErrors(['grams_at.'.$this->store->id])
            ->assertSee('Cada sede necesita una cantidad mayor que cero; quita la sede que no recibe nada.');
        $this->assertSame([], $this->parts());

        $prerolls = Genetic::factory()->create(['organisation_id' => $this->org->id, 'product_type' => ProductType::PREROLL, 'grams_per_unit_cg' => 100]);
        $page = Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $prerolls->product_type->value, 'genetic_id' => $prerolls->id])
            ->set('data.location_id', [$this->centro->id, $this->store->id])
            ->fillForm(['units' => '7'])
            ->call('splitEqually');
        $this->assertSame(['4', '3'], array_values(array_map('strval', (array) $page->get('data.units_at'))));
    }
}
