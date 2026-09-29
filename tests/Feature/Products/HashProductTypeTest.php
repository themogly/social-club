<?php

namespace Tests\Feature\Products;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\ConcentrateSubtype;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\UnitType;
use App\Exceptions\LimitExceededException;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\Support\StockCeiling;
use App\ViewModels\Reports\ConsumptionReport;
use App\ViewModels\Reports\StockReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\FiltersTheCatalogueLikeTheBrowser;
use Tests\TestCase;

/**
 * Prompt 276 (Ben's 269) made Hachís a first-level choice; prompt 280 made it a REAL product type (Ben: "hash should be a
 * separate product type") — `ProductType::HASH`, WEIGHT, so it dispenses on exactly the flower path and is its own
 * reporting category. Existing CONCENTRATE/HASH strains were migrated to it.
 */
class HashProductTypeTest extends TestCase
{
    use FiltersTheCatalogueLikeTheBrowser, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app()->setLocale('es');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    /** A sellable WEIGHT genetic at the location: a base price and a 100 g open batch. */
    private function sellable(Genetic $genetic): Batch
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);

        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 10000, 'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);
    }

    private function member(Location $location, int $daily = 100000, int $monthly = 100000): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => $daily, 'monthly_limit_cg' => $monthly,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    // (1) Creating a strain with type Hachís stores a WEIGHT product and the strain list shows "Hachís".
    public function test_creating_a_hachis_strain_stores_a_weight_concentrate_and_the_list_reads_hachis(): void
    {
        Livewire::actingAs($this->owner)->test(CreateGenetic::class)
            ->assertFormFieldExists('product_type', fn ($field): bool => array_key_exists('HASH', $field->getOptions())
                && $field->getOptions()['HASH'] === 'Hachís')
            ->fillForm(['name' => 'Marroquí', 'product_type' => 'HASH'])
            ->call('create')
            ->assertHasNoFormErrors();

        $genetic = Genetic::query()->withoutGlobalScopes()->where('name', 'Marroquí')->sole();
        $this->assertSame(ProductType::HASH, $genetic->product_type);
        $this->assertNull($genetic->concentrate_subtype);
        $this->assertSame(UnitType::WEIGHT, $genetic->unit_type);

        // Its stock comes through «Crear lote» (prompt 320), by weight like flower.
        Livewire::actingAs($this->owner)->test(CreateBatch::class)
            ->fillForm(['location_id' => $this->location->id, 'genetic_id' => $genetic->id, 'grams' => '50', 'cost_per_gram_eur' => '3', 'sale_price_eur' => '7'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(5000, Batch::query()->withoutGlobalScopes()->where('genetic_id', $genetic->id)->sole()->getRawOriginal('remaining_cg'));

        Livewire::actingAs($this->owner)->test(ListGenetics::class)
            ->assertCanSeeTableRecords([$genetic])
            ->assertTableColumnFormattedStateSet('product_type', 'Hachís', $genetic)
            ->assertTableColumnFormattedStateNotSet('product_type', 'Extracto', $genetic);

        // And the list's type filter narrows to Hachís alone.
        $rosin = Genetic::factory()->concentrate(ConcentrateSubtype::ROSIN)->create(['organisation_id' => $this->org->id]);
        $flower = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Livewire::actingAs($this->owner)->test(ListGenetics::class)
            ->filterTable('product_type', 'HASH')
            ->assertCanSeeTableRecords([$genetic])->assertCanNotSeeTableRecords([$rosin, $flower])
            ->filterTable('product_type', 'CONCENTRATE')
            ->assertCanSeeTableRecords([$rosin])->assertCanNotSeeTableRecords([$genetic, $flower]);
    }

    // (2) A hash strain dispenses by weight and counts toward limits and the ceiling exactly like flower.
    public function test_a_hash_dispensation_counts_toward_limits_and_the_ceiling_exactly_like_flower(): void
    {
        $hashLocation = $this->location;
        $flowerLocation = Location::factory()->create(['organisation_id' => $this->org->id]);

        $hash = Genetic::factory()->create(['organisation_id' => $this->org->id, 'product_type' => ProductType::HASH]);
        $hashBatch = $this->sellable($hash);
        $flower = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $flower->id, 'location_id' => $flowerLocation->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $flowerBatch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $flower->id, 'location_id' => $flowerLocation->id,
            'initial_cg' => 10000, 'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        $hashMember = $this->member($hashLocation, daily: 350, monthly: 350);
        $flowerMember = $this->member($flowerLocation, daily: 350, monthly: 350);

        // 3.50 g of each — the member's whole daily and monthly allowance.
        $hashLine = (new CommitDispensation)->handle($hashMember, $hashLocation, [
            ['genetic_id' => $hash->id, 'batch_id' => $hashBatch->id, 'grams_cg' => 350],
        ])->lines->first();
        $flowerLine = app(ActiveScope::class)->forLocation($flowerLocation->id, fn () => (new CommitDispensation)->handle($flowerMember, $flowerLocation, [
            ['genetic_id' => $flower->id, 'batch_id' => $flowerBatch->id, 'grams_cg' => 350],
        ])->lines->first());

        // Dispensed by weight: grams on the line, no units, the same total.
        $this->assertSame(350, $hashLine->grams_cg->centigrams);
        $this->assertNull($hashLine->units_dispensed);
        $this->assertSame($flowerLine->grams_cg->centigrams, $hashLine->grams_cg->centigrams);
        $this->assertSame($flowerLine->line_total_cents->cents, $hashLine->line_total_cents->cents);

        // The stock moved in centigrams through the one writer, the same as flower.
        $this->assertSame(9650, $hashBatch->fresh()->remaining_cg->centigrams);
        $this->assertSame($flowerBatch->fresh()->remaining_cg->centigrams, $hashBatch->fresh()->remaining_cg->centigrams);
        $movement = fn (Batch $b): StockMovement => StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_id', $b->id)->where('type', 'DISPENSE')->sole();
        $this->assertSame(-350, $movement($hashBatch)->qty_cg->centigrams);
        $this->assertSame($movement($flowerBatch)->qty_cg->centigrams, $movement($hashBatch)->qty_cg->centigrams);

        // Daily and monthly used — the same figures.
        // Each read runs at its own sede, as the counter would.
        $at = fn (Location $l, callable $fn): mixed => app(ActiveScope::class)->forLocation($l->id, $fn);
        $hashLimits = $at($hashLocation, fn () => (new ResolveMemberLimits)->handle($hashMember, $hashLocation));
        $flowerLimits = $at($flowerLocation, fn () => (new ResolveMemberLimits)->handle($flowerMember, $flowerLocation));
        $this->assertSame(350, $hashLimits->dailyUsedCg);
        $this->assertSame($flowerLimits->dailyUsedCg, $hashLimits->dailyUsedCg);
        $this->assertSame($flowerLimits->monthlyUsedCg, $hashLimits->monthlyUsedCg);

        // The next 0.01 g is blocked for both.
        foreach ([[$hashMember, $hashLocation, $hash, $hashBatch], [$flowerMember, $flowerLocation, $flower, $flowerBatch]] as [$m, $l, $g, $b]) {
            try {
                $at($l, fn () => (new CommitDispensation)->handle($m, $l, [['genetic_id' => $g->id, 'batch_id' => $b->id, 'grams_cg' => 1]]));
                $this->fail('A dispensation over the limit must be blocked for '.$g->product_type->label());
            } catch (LimitExceededException) {
                $this->assertTrue(true);
            }
        }

        // The consumption report counts the same grams, under the type staff know.
        $byGenetic = collect((new ConsumptionReport($this->org->id, [$hashLocation->id], Period::today()))->tables())->firstWhere('key', 'genetics');
        $this->assertSame([['Hachís', 350]], collect($byGenetic->rows)->map(fn (array $r): array => [$r['tipo'], $r['grams']])->all());

        // The stock ceiling counts the on-site hash exactly as it counts flower.
        $this->assertSame(9650, StockCeiling::forLocation($hashLocation)['on_site_cg']);
        $this->assertSame(StockCeiling::forLocation($flowerLocation)['on_site_cg'], StockCeiling::forLocation($hashLocation)['on_site_cg']);
    }

    // (3) An existing CONCENTRATE/HASH strain is migrated to Hachís, and reads as Hachís everywhere.
    public function test_an_existing_concentrate_hash_strain_is_migrated_to_hachis(): void
    {
        // The pre-280 shape, written raw (the HASH subtype no longer exists on the enum).
        $hash = Genetic::factory()->concentrate(ConcentrateSubtype::ROSIN)->create(['organisation_id' => $this->org->id, 'name' => 'Polen']);
        DB::table('genetics')->where('id', $hash->id)->update(['concentrate_subtype' => 'HASH']);
        $rosin = Genetic::factory()->concentrate(ConcentrateSubtype::ROSIN)->create(['organisation_id' => $this->org->id, 'name' => 'Rosin Uno']);
        $flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);

        (require database_path('migrations/2026_09_28_200000_hash_is_a_product_type.php'))->up();

        $hash->refresh();
        $this->assertSame(ProductType::HASH, $hash->product_type);
        $this->assertNull($hash->concentrate_subtype);
        $this->assertSame(UnitType::WEIGHT, $hash->unit_type);
        $this->assertSame(ProductType::CONCENTRATE, $rosin->fresh()->product_type, 'a non-hash extract was moved');
        $this->assertSame('Hachís', $hash->product_type->label());
        app()->setLocale('en');
        $this->assertSame('Hash', $hash->product_type->label());
        app()->setLocale('es');

        // The edit form opens on Hachís, and saving it keeps it Hachís.
        Livewire::actingAs($this->owner)->test(EditGenetic::class, ['record' => $hash->getRouteKey()])
            ->assertFormSet(['product_type' => 'HASH'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(ProductType::HASH, $hash->fresh()->product_type);

        // The counter: the row reads Hachís, Hachís is its own chip, and it filters to hash alone.
        foreach ([$hash, $rosin, $flower] as $g) {
            $this->sellable($g);
        }
        $onHand = collect((new StockReport($this->org->id, [$this->location->id], Period::today()))->tables())->firstWhere('key', 'on_hand');
        $this->assertSame(['Extracto', 'Flor', 'Hachís'], collect($onHand->rows)->pluck('tipo')->sort()->values()->all());

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->location->id]);
        $this->actingAs($staff);
        CounterOperator::set($staff);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member($this->location)->id);
        $productTypes = collect($pos->invade()->islandView('header')['productTypes'])->pluck('label', 'value')->all();
        $this->assertSame('Hachís', $productTypes['HASH'] ?? null);
        $this->assertSame('Extracto', $productTypes['CONCENTRATE'] ?? null);
        $this->assertStringNotContainsString('Extracto · Hachís', $pos->html());

        // The Tipo filter runs in the browser (prompt 293) over each card's own type: Hachís and Extracto are apart.
        $html = $pos->html();
        $this->assertSame(['Polen'], $this->visibleInBrowser($html, 'genetics', ['productType' => 'HASH']));
        $this->assertSame(['Rosin Uno'], $this->visibleInBrowser($html, 'genetics', ['productType' => 'CONCENTRATE']));
        $this->assertStringContainsString("x-on:click=\"filter('productType', 'HASH')\"", $html);
    }

    // (4) Extracto no longer offers Hachís as a subtype — on the wizard and on the edit form.
    public function test_extracto_no_longer_offers_hachis_as_a_subtype(): void
    {
        $this->assertSame(['ROSIN', 'SHATTER', 'WAX', 'LIVE_RESIN'], array_map(fn (ConcentrateSubtype $c): string => $c->value, ConcentrateSubtype::cases()));

        $noHash = fn ($field): bool => ! array_key_exists('HASH', $field->getOptions()) && array_key_exists('ROSIN', $field->getOptions());

        Livewire::actingAs($this->owner)->test(CreateGenetic::class)
            ->fillForm(['product_type' => 'CONCENTRATE'])
            ->assertFormFieldExists('concentrate_subtype', $noHash);

        $rosin = Genetic::factory()->concentrate(ConcentrateSubtype::ROSIN)->create(['organisation_id' => $this->org->id]);
        Livewire::actingAs($this->owner)->test(EditGenetic::class, ['record' => $rosin->getRouteKey()])
            ->assertFormSet(['product_type' => 'CONCENTRATE', 'concentrate_subtype' => 'ROSIN'])
            ->assertFormFieldExists('concentrate_subtype', $noHash)
            // Extracto + a smuggled HASH subtype is refused — Hachís is its own product type.
            ->fillForm(['concentrate_subtype' => 'HASH'])
            ->call('save')
            ->assertHasFormErrors(['concentrate_subtype']);
        $this->assertSame(ConcentrateSubtype::ROSIN, $rosin->fresh()->concentrate_subtype);
    }
}
