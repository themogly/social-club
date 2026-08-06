<?php

namespace Tests\Feature\Filament;

use App\Casts\MoneyCast;
use App\Casts\WeightCast;
use App\Enums\DiscountMode;
use App\Enums\Role;
use App\Enums\UnitType;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Discounts\DiscountResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A model column cast to a value object (Money/Weight) cannot sit in Livewire form state: Filament
 * seeds the form from the WHOLE record, Livewire's `dehydrateProperties()` cannot serialise the
 * object, and the Edit page throws during mount — "Property type not supported:
 * [{"centigrams":10000}]". The record is fine; the page is unopenable.
 *
 * This test used to HAND-LIST the five money-backed pages, which is exactly why `EditBatch` was
 * missing: it is weight-backed, nobody revisited the list when `WeightCast` arrived on Batch, and
 * the defect shipped (prompt 166). The list is now DERIVED from the models' own casts, so the next
 * object-cast column added to a model whose Edit page nobody revisited fails here in milliseconds
 * instead of 500ing in front of a member of staff.
 */
class EditPageMountTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');
    }

    // --- The guard ---------------------------------------------------------------------------

    public function test_every_resource_whose_model_carries_an_object_cast_has_mount_coverage(): void
    {
        $derived = $this->resourcesCarryingAnObjectCast();
        $covered = array_keys($this->fixtures());

        // Sanity: the derivation must actually see the resource this branch fixed. If this fails
        // the guard is looking in the wrong place, and every assertion below it is vacuous.
        $this->assertContains(BatchResource::class, $derived,
            'The derivation no longer detects Batch, whose initial_cg/remaining_cg cast through WeightCast.');

        $this->assertSame([], array_values(array_diff($derived, $covered)),
            "These resources' models cast a column to a value object but their Edit page is not mounted by ".
            'this test. Add a fixture below — and check the page drops the cast keys in mutateFormDataBeforeFill.');
    }

    public function test_every_object_cast_edit_page_mounts_without_leaking_a_cast_object(): void
    {
        foreach ($this->fixtures() as $resource => $make) {
            $record = $make();

            Livewire::actingAs($this->owner)
                ->test($resource::getPages()['edit']->getPage(), ['record' => $record->getRouteKey()])
                ->assertOk();
        }
    }

    // --- Batches specifically: the page that was broken ----------------------------------------

    public function test_an_existing_batch_edit_page_opens(): void
    {
        $batch = $this->weightBatch();

        Livewire::actingAs($this->owner)
            ->test(EditBatch::class, ['record' => $batch->getRouteKey()])
            ->assertOk();
    }

    public function test_a_per_unit_batch_edit_page_opens_too(): void
    {
        // The other half of the one-of-two rule Batch::booted() enforces — cg columns null, unit
        // columns set. It carries no Weight objects at all, so it proves the fix is not something
        // that only happens to work when the cg columns are populated.
        // unit_type is derived from product_type by GeneticObserver — never set directly.
        $genetic = Genetic::factory()->preroll()->create(['organisation_id' => $this->org->id]);
        $this->assertSame(UnitType::UNIT, $genetic->unit_type);

        $batch = Batch::factory()->units(40, 40)->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'genetic_id' => $genetic->getKey(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(EditBatch::class, ['record' => $batch->getRouteKey()])
            ->assertOk();
    }

    public function test_editing_a_batch_saves_the_metadata_and_leaves_stock_untouched(): void
    {
        $batch = $this->weightBatch();
        $initial = $batch->getRawOriginal('initial_cg');
        $remaining = $batch->getRawOriginal('remaining_cg');

        // What edit actually offers: dates, lab report and notes. Quantity AND cost/gram are
        // create-only — cost is carried onto the batch by RecordPurchase at intake.
        Livewire::actingAs($this->owner)
            ->test(EditBatch::class, ['record' => $batch->getRouteKey()])
            ->fillForm(['expires_on' => '2027-01-31', 'notes' => 'Revisado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $batch->fresh();
        $this->assertSame('2027-01-31', $fresh->expires_on->toDateString());
        $this->assertSame('Revisado', $fresh->notes);
        // Stock moves ONLY through the ledger — an edit here may never touch it.
        $this->assertSame($initial, $fresh->getRawOriginal('initial_cg'));
        $this->assertSame($remaining, $fresh->getRawOriginal('remaining_cg'));
    }

    public function test_the_stock_columns_are_dropped_from_the_fill(): void
    {
        // The hazard is real: both columns cast to a value object, and Filament fills from the whole
        // record regardless of which fields the form actually declares.
        $casts = (new Batch)->getCasts();
        $this->assertSame(WeightCast::class, $casts['initial_cg']);
        $this->assertSame(WeightCast::class, $casts['remaining_cg']);

        $batch = $this->weightBatch();
        $page = new EditBatch;
        $page->record = $batch;

        $mutate = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $mutate->setAccessible(true);
        /** @var array<string, mixed> $filled */
        $filled = $mutate->invoke($page, $batch->attributesToArray());

        $this->assertArrayNotHasKey('initial_cg', $filled);
        $this->assertArrayNotHasKey('remaining_cg', $filled);
        $this->assertArrayHasKey('batch_no', $filled, 'Only the cast columns should have been dropped.');
    }

    // --- Fixtures ------------------------------------------------------------------------------

    private function weightBatch(): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        return Batch::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'genetic_id' => $genetic->getKey(),
            'initial_cg' => 100000,
            'remaining_cg' => 10000,
        ]);
    }

    /**
     * One persisted record per resource whose model carries an object cast. Keyed by resource so
     * the derivation above can prove the set is complete.
     *
     * @return array<class-string<resource>, callable(): Model>
     */
    private function fixtures(): array
    {
        return [
            ArticleResource::class => fn (): Model => Article::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'price_cents' => 500,
            ]),
            BatchResource::class => fn (): Model => $this->weightBatch(),
            DiscountResource::class => fn (): Model => Discount::factory()->create([
                'organisation_id' => $this->org->id, 'mode' => DiscountMode::FIXED, 'value_cents' => 300, 'value_bp' => null,
            ]),
            ExpenseResource::class => fn (): Model => Expense::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'amount_cents' => 1000,
                'category_id' => ExpenseCategory::factory()->create(['organisation_id' => $this->org->id])->getKey(),
            ]),
            MembershipTierResource::class => fn (): Model => MembershipTier::factory()->create([
                'organisation_id' => $this->org->id, 'default_fee_cents' => 2000, 'daily_limit_cg' => 350,
            ]),
            PurchaseResource::class => fn (): Model => Purchase::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'supplier_id' => Supplier::factory()->create(['organisation_id' => $this->org->id])->getKey(),
                'amount_cents' => 50000, 'paid_cents' => 30000,
            ]),
        ];
    }

    /**
     * Every Filament resource with an Edit page whose MODEL casts a column to a value object.
     * Derived from the casts themselves — never a hand-maintained list, which is what failed.
     *
     * @return list<class-string<resource>>
     */
    private function resourcesCarryingAnObjectCast(): array
    {
        $objectCasts = [MoneyCast::class, WeightCast::class];
        $found = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) ?: [] as $file) {
            $class = 'App\\'.Str::of($file)->after(app_path().'/')->beforeLast('.php')->replace('/', '\\');

            if (! class_exists($class) || ! is_subclass_of($class, Resource::class)) {
                continue;
            }
            if (! array_key_exists('edit', $class::getPages())) {
                continue;
            }

            $model = $class::getModel();
            if (! is_subclass_of($model, Model::class)) {
                continue;
            }

            if (array_intersect($objectCasts, array_values((new $model)->getCasts())) !== []) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }
}
