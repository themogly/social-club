<?php

namespace Tests\Feature\Products;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\UnitType;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\ViewModels\Reports\ConsumptionReport;
use App\ViewModels\Reports\StockReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 328 — Ben: "We need to add vapes to the category, along with hash and flower." *Vapeador* is its own product
 * type: dispensed per cartridge or disposable (UNIT), counted by its oil weight (`grams_per_unit_cg`) exactly like a
 * pre-roll — never 326's THC-mg conversion, which is for edibles. Follows 280's pattern (Hachís).
 */
class VapeProductTypeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->location->id]);
        $this->actingAs($this->owner);
    }

    private function vape(string $name = 'Vape Mango', int $cgPerUnit = 50): Genetic
    {
        return Genetic::factory()->vape($cgPerUnit)->create(['organisation_id' => $this->org->id, 'name' => $name]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 1000, 'monthly_limit_cg' => 10000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    // --- 1. The type ------------------------------------------------------------------------------------------------------

    public function test_vape_is_a_unit_product_type_labelled_vapeador(): void
    {
        $this->assertSame(UnitType::UNIT, ProductType::VAPE->unitType());
        $this->assertSame('Vapeador', ProductType::VAPE->label());
        app()->setLocale('en');
        $this->assertSame('Vape', ProductType::VAPE->label());

        // 6 — every match over the type has an arm for every case (an unhandled one would throw here, and fail Larastan).
        foreach (ProductType::cases() as $type) {
            $this->assertNotSame('', $type->label());
            $this->assertInstanceOf(UnitType::class, $type->unitType());
        }
    }

    // --- 2. The strain form --------------------------------------------------------------------------------------------------

    public function test_a_vape_strain_asks_its_oil_weight_per_unit_and_no_thc_mg(): void
    {
        Livewire::test(CreateGenetic::class)->fillForm(['product_type' => ProductType::VAPE->value])
            ->assertFormFieldIsVisible('grams_per_unit_g')
            ->assertFormFieldExists('grams_per_unit_g', fn ($field): bool => $field->getLabel() === __('Peso por unidad (g)'))
            ->assertSee(__('Contenido de aceite de cada cartucho o desechable. Es lo que cuenta para límites y existencias.'))
            ->assertFormFieldIsHidden('thc_mg_per_unit');

        Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Sin peso', 'product_type' => ProductType::VAPE->value])
            ->call('create')->assertHasFormErrors(['grams_per_unit_g']);

        Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Vape Uva', 'product_type' => ProductType::VAPE->value, 'grams_per_unit_g' => '0.5', 'thc_pct' => '80'])
            ->call('create')->assertHasNoFormErrors();
        $vape = Genetic::query()->where('name', 'Vape Uva')->sole();
        $this->assertSame([50, UnitType::UNIT, 8000, null], [$vape->grams_per_unit_cg, $vape->unit_type, $vape->thc_bp, $vape->thc_mg_per_unit]);
    }

    // --- 3. Crear lote --------------------------------------------------------------------------------------------------------

    public function test_crear_lote_with_vapeador_offers_only_vapes_in_units(): void
    {
        $vape = $this->vape();
        Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        Genetic::factory()->preroll()->create(['organisation_id' => $this->org->id, 'name' => 'Porro']);

        $page = Livewire::test(CreateBatch::class)->fillForm(['product_type' => ProductType::VAPE->value]);
        $options = $page->instance()->getSchema('form')->getComponent(fn ($c): bool => $c instanceof Select && $c->getName() === 'genetic_id', withHidden: true)->getOptions();
        $this->assertSame([$vape->id], array_keys($options));

        $page->fillForm(['genetic_id' => $vape->id])->assertFormFieldIsVisible('units')->assertFormFieldIsHidden('grams');
    }

    // --- 4. The counter ---------------------------------------------------------------------------------------------------------

    public function test_two_half_gram_vapes_count_one_gram_and_have_their_own_chip(): void
    {
        $vape = $this->vape();
        GeneticPrice::factory()->perUnit(1500)->create(['organisation_id' => $this->org->id, 'genetic_id' => $vape->id, 'location_id' => $this->location->id]);
        $batch = Batch::factory()->units(20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $vape->id, 'location_id' => $this->location->id]);
        $member = $this->member();

        (new CommitDispensation)->handle($member, $this->location, [['genetic_id' => $vape->id, 'batch_id' => $batch->id, 'units' => 2]]);

        $this->assertSame(100, DispensationLine::query()->sole()->getRawOriginal('grams_cg'));
        $this->assertSame(100, (new ResolveMemberLimits)->handle($member, $this->location)->dailyUsedCg);

        CounterOperator::set($this->owner);
        session(['counter.location_id' => $this->location->id]);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id);
        $types = collect($pos->invade()->islandView('header')['productTypes'])->pluck('label', 'value')->all();
        $this->assertSame('Vapeador', $types['VAPE'] ?? null);
        $this->assertStringContainsString('data-type="VAPE"', $pos->html());

        // The member menu lists it (with its THC %, as for extracts).
        $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->assertSee('Vape Mango');
    }

    // --- 5. Reports ---------------------------------------------------------------------------------------------------------------

    public function test_the_consumption_and_stock_reports_group_vapes_as_vapeador(): void
    {
        $vape = $this->vape();
        GeneticPrice::factory()->perUnit(1500)->create(['organisation_id' => $this->org->id, 'genetic_id' => $vape->id, 'location_id' => $this->location->id]);
        $batch = Batch::factory()->units(20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $vape->id, 'location_id' => $this->location->id]);
        (new CommitDispensation)->handle($this->member(), $this->location, [['genetic_id' => $vape->id, 'batch_id' => $batch->id, 'units' => 2]]);

        $consumption = collect((new ConsumptionReport($this->org->id, [$this->location->id], Period::today()))->tables())->firstWhere('key', 'genetics');
        $this->assertSame(['Vapeador'], collect($consumption->rows)->pluck('tipo')->all());

        $onHand = collect((new StockReport($this->org->id, [$this->location->id], Period::today()))->tables())->firstWhere('key', 'on_hand');
        $this->assertSame(['Vapeador'], collect($onHand->rows)->pluck('tipo')->all());
    }
}
