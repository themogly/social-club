<?php

namespace Tests\Feature\Stock;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\RenameBatchLote;
use App\Actions\Till\OpenTill;
use App\Enums\LocationKind;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exceptions\StockCeilingExceededException;
use App\Filament\Resources\Batches\BatchActions;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
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
use App\ViewModels\BatchRecall;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 305 — real stock goes in in stages: a lote that exists at the store and one club gains parts at the other clubs
 * as each is weighed (*Añadir existencias en otra sede*), and any part is set to what the scale says (*Recuento*).
 */
class AddPartsAndRecountTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $store;

    private Location $centro;

    private Location $norte;

    private Location $sur;

    private User $owner;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es'); // the assertions read the Spanish copy («y», «Recuento»)
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Storage house', 'kind' => LocationKind::ALMACEN]);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->sur = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Sur']);
        app(ActiveScope::class)->setLocation(null);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
    }

    /** Day 1: the store and the busiest club (303's split). */
    private function lote(): Batch
    {
        return (new IntakeBatch)->handleParts($this->genetic, [
            ['location' => $this->store, 'grams' => '800'], ['location' => $this->centro, 'grams' => '200'],
        ], ['label' => 'Cosecha verano', 'price_per_gram_cents' => 1000, 'cost_per_gram_cents' => 400])->first();
    }

    /** @return list<Batch> */
    private function parts(): array
    {
        return Batch::query()->withoutGlobalScopes()->where('genetic_id', $this->genetic->id)->get()->all();
    }

    // --- 1. Adding parts ---------------------------------------------------------------------------------------------

    public function test_adding_stock_at_other_sedes_joins_the_same_lote(): void
    {
        $lote = $this->lote();

        $this->assertEqualsCanonicalizing([$this->norte->id, $this->sur->id], array_keys(BatchActions::remainingSedes($lote)), 'offered a sede that already holds a part');

        // The sedes are set as ONE update, as the select sends them (the helper would feed them one by one).
        Livewire::test(ListBatches::class)
            ->mountTableAction('addParts', $lote)
            ->set('mountedActions.0.data.location_id', [$this->norte->id, $this->sur->id])
            ->setTableActionData(['grams_at' => [$this->norte->id => '300', $this->sur->id => '150']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('Añadido a :sedes', ['sedes' => 'Sede Norte y Sede Sur']));

        $added = collect($this->parts())->whereIn('location_id', [$this->norte->id, $this->sur->id])->values();
        $this->assertCount(2, $added);
        foreach ($added as $part) {
            $this->assertSame([$lote->batch_no, $lote->lote_seq, 'Cosecha verano', 1000, 400, null],
                [$part->batch_no, $part->lote_seq, $part->label, $part->price_per_gram_cents, $part->cost_per_gram_cents, $part->parent_batch_id]);
            $movement = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $part->id)->sole();
            $this->assertSame([StockMovementType::INTAKE, $part->location_id, __('Recuento inicial')], [$movement->type, $movement->location_id, $movement->reason]);
        }
        $this->assertSame([30000, 15000], [$added->firstWhere('location_id', $this->norte->id)->remaining_cg->centigrams, $added->firstWhere('location_id', $this->sur->id)->remaining_cg->centigrams]);
        $this->assertSame(1, AuditLog::query()->where('action', 'batch.part_added')->count());
    }

    public function test_the_ceiling_applies_to_added_parts_with_one_override(): void
    {
        $lote = $this->lote();
        $matrix = Settings::get('enforcement', Settings::DEFAULTS['enforcement']);
        $matrix['stock']['ceiling'] = 'BLOCK';
        Settings::set('enforcement', $matrix);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->norte->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE]);

        try {
            (new IntakeBatch)->addParts($lote, [['location' => $this->norte, 'grams' => '50']], []);
            $this->fail('an added part over a BLOCK ceiling was created');
        } catch (StockCeilingExceededException) {
        }
        $this->assertCount(2, $this->parts());

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        (new IntakeBatch)->addParts($lote, [['location' => $this->norte, 'grams' => '50']], ['override' => true, 'override_by' => $manager, 'override_reason' => 'Recuento de la sede']);
        $this->assertCount(3, $this->parts());
        $this->assertSame([$this->norte->id], array_column((array) AuditLog::query()->where('action', 'stock.ceiling.overridden')->sole()->after['breaches'], 'location_id'));
    }

    public function test_a_lote_at_every_sede_does_not_offer_the_action(): void
    {
        $lote = $this->lote();
        (new IntakeBatch)->addParts($lote, [['location' => $this->norte, 'grams' => '10'], ['location' => $this->sur, 'grams' => '10']], []);

        $this->assertSame([], BatchActions::remainingSedes($lote->fresh()));
        Livewire::test(ListBatches::class)->assertTableActionHidden('addParts', $lote);

        try {
            (new IntakeBatch)->addParts($lote->fresh(), [['location' => $this->norte, 'grams' => '5']], []);
            $this->fail('a second part was added at a sede that already holds one');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Recuento', $e->getMessage());
        }
        $this->assertCount(4, $this->parts());
    }

    public function test_recall_and_rename_reach_the_added_parts(): void
    {
        $lote = $this->lote();
        $added = (new IntakeBatch)->addParts($lote, [['location' => $this->norte, 'grams' => '100']], [])->first();
        $added->forceFill(['price_per_gram_cents' => 900])->save();
        $this->owner->locations()->sync([$this->norte->id]);
        $till = (new OpenTill)->handle($this->norte, 'POS-1', 1000);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Servido',
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->norte->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);
        (new CommitDispensation)->handle($member, $this->norte, [['genetic_id' => $this->genetic->id, 'batch_id' => $added->id, 'grams_cg' => 100]],
            ['operator_id' => $this->owner->id, 'till_session_id' => $till->id, 'cash_cents' => 900, 'wallet_cents' => 0]);

        $this->assertSame(['Servido'], array_map(fn (array $r): string => explode(' ', $r['socio'])[0], (new BatchRecall($lote->fresh()))->rows()));

        (new RenameBatchLote)->handle($lote, 'Cosecha otoño');
        $this->assertSame(['Cosecha otoño'], collect($this->parts())->pluck('label')->unique()->values()->all());
    }

    // --- 5–6. Recuento --------------------------------------------------------------------------------------------------

    public function test_a_count_sets_the_part_to_what_the_scale_says(): void
    {
        $this->lote();
        $centro = collect($this->parts())->firstWhere('location_id', $this->centro->id);
        $centro->forceFill(['remaining_cg' => 21240])->saveQuietly(); // the system says 212,40 g

        Livewire::test(ListBatches::class)
            ->callTableAction('recount', $centro, ['counted' => '208.10', 'reason' => 'Recuento'])
            ->assertHasNoTableActionErrors();
        $adjustment = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $centro->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertSame(-430, $adjustment->qty_cg->centigrams);
        $this->assertSame(20810, $centro->fresh()->remaining_cg->centigrams);

        Livewire::test(ListBatches::class)->callTableAction('recount', $centro, ['counted' => '208.10', 'reason' => 'Recuento'])
            ->assertNotified(__('Sin diferencia'));
        $this->assertSame(1, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $centro->id)->where('type', StockMovementType::ADJUSTMENT->value)->count());

        Livewire::test(ListBatches::class)->callTableAction('recount', $centro, ['counted' => '0', 'reason' => 'Vacío']);
        $this->assertSame(0, $centro->fresh()->remaining_cg->centigrams);
    }

    public function test_a_sale_between_opening_and_saving_is_not_counted_twice(): void
    {
        $this->lote();
        $centro = collect($this->parts())->firstWhere('location_id', $this->centro->id);

        $page = Livewire::test(ListBatches::class)->mountTableAction('recount', $centro); // the form shows 200,00 g
        $centro->fresh()->forceFill(['remaining_cg' => 19000])->save(); // a 10 g sale lands meanwhile
        $page->setTableActionData(['counted' => '185', 'reason' => 'Recuento'])->callMountedTableAction()->assertHasNoTableActionErrors();

        $adjustment = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $centro->id)->where('type', StockMovementType::ADJUSTMENT->value)->sole();
        $this->assertSame(-500, $adjustment->qty_cg->centigrams, 'the difference was taken against the stale figure');
        $this->assertSame(18500, $centro->fresh()->remaining_cg->centigrams);
    }

    // --- 7–8. Permissions and the panel --------------------------------------------------------------------------------

    public function test_the_actions_follow_their_permissions(): void
    {
        $lote = $this->lote();
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->store->id, $this->centro->id, $this->norte->id, $this->sur->id]);
        $this->setRolePermission(Role::MANAGER, 'stock.take', false);
        $this->setRolePermission(Role::MANAGER, 'stock.manage', true);
        $this->actingAs($manager->fresh());
        app(ActiveScope::class)->setLocation($this->store->id);
        Livewire::test(ListBatches::class)->assertTableActionHidden('recount', $lote)->assertTableActionVisible('addParts', $lote);

        // Without `stock.manage` the batch list itself is closed (it is the batches' view permission), so the action is
        // checked on its definition.
        $this->setRolePermission(Role::MANAGER, 'stock.take', true);
        $this->setRolePermission(Role::MANAGER, 'stock.manage', false);
        $this->actingAs($manager->fresh());
        $this->assertFalse(BatchActions::addParts()->record($lote)->isVisible());
        $this->assertTrue(BatchActions::recount()->record($lote)->isVisible());
    }

    public function test_the_batch_page_lists_every_part_of_the_lote(): void
    {
        $lote = $this->lote();
        (new IntakeBatch)->addParts($lote, [['location' => $this->norte, 'grams' => '150']], []);

        Livewire::test(EditBatch::class, ['record' => $lote->getRouteKey()])
            ->assertSee(__('Partes del lote'))
            ->assertSeeText('Storage house')->assertSeeText('Sede Centro')->assertSeeText('Sede Norte')
            ->assertSee('data-lote-part', false)
            ->assertActionVisible('addParts')->assertActionVisible('recount');
    }
}
