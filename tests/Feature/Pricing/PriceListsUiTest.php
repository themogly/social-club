<?php

namespace Tests\Feature\Pricing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\MemberStatus;
use App\Enums\PriceList;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\PreciosSede;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\DiscountsRelationManager;
use App\Filament\Resources\MembershipTiers\Pages\CreateMembershipTier;
use App\Filament\Resources\MembershipTiers\Pages\EditMembershipTier;
use App\Filament\Resources\MembershipTiers\Pages\ListMembershipTiers;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 382 — where the price lists show: the *Tarifas* form, the discounts that can no longer be created, the member page,
 * *Salud del sistema*, a new batch's prices, the *Precio* action, the links, and the counter's basket, member chip and receipt.
 */
class PriceListsUiTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Genetic $flower;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL);
        Settings::set('discount_rounding', 'none', SettingType::STRING);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        $this->batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'cost_per_gram_cents' => 300, 'lote_seq' => 3,
            'price_per_gram_cents' => 1000, 'price_per_eighth_cents' => 3000, 'local_price_per_gram_cents' => 880]);
    }

    private function member(PriceList $list): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0, 'price_list' => $list]);
        (new EnrolMembership)->handle($member, $this->sede, $tier, ['actor' => $this->owner, 'fee_cents' => 0]);

        return $member;
    }

    private function staffDiscount(int $bp = 2500): Discount
    {
        $discount = Discount::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Plantilla', 'kind' => DiscountKind::STAFF, 'mode' => DiscountMode::PERCENT,
            'value_bp' => $bp, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $discount->locations()->sync([$this->sede->id]);

        return $discount;
    }

    // --- Tarifas -------------------------------------------------------------------------------------------------------------

    public function test_a_tier_picks_its_price_list_as_big_buttons_and_the_old_percentage_is_gone(): void
    {
        Livewire::test(CreateMembershipTier::class)
            ->assertFormFieldExists('price_list', fn ($field): bool => $field instanceof ToggleButtons)
            ->assertFormFieldDoesNotExist('discount_pct')
            ->assertSee('Estándar')->assertSee('Local')->assertSee('Personal')
            ->fillForm(['name' => 'Personal', 'default_fee_eur' => '0', 'default_period' => 'YEARLY', 'price_list' => 'STAFF'])
            ->call('create')->assertHasNoFormErrors();
        $tier = MembershipTier::query()->where('name', 'Personal')->sole();
        $this->assertSame(PriceList::STAFF, $tier->price_list);

        $old = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 1500]);
        Livewire::test(EditMembershipTier::class, ['record' => $old->id])->assertFormSet(['price_list' => 'STANDARD'])
            ->fillForm(['price_list' => 'LOCAL'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(PriceList::LOCAL, $old->fresh()->price_list);
        $this->assertSame(1500, (int) $old->fresh()->discount_bp, 'kept so history reads');
    }

    public function test_the_tiers_list_shows_which_prices_each_tier_pays(): void
    {
        $local = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Vecinos', 'price_list' => PriceList::LOCAL]);
        $standard = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Turistas']);

        Livewire::test(ListMembershipTiers::class)
            ->assertTableColumnExists('price_list')
            ->assertTableColumnFormattedStateSet('price_list', 'Local', $local)
            ->assertTableColumnFormattedStateSet('price_list', 'Estándar', $standard);
    }

    // --- The default % (owner only) ---------------------------------------------------------------------------------------

    public function test_the_default_percentages_are_owner_only_settings(): void
    {
        Livewire::test(ManageSettings::class)
            ->assertFormSet(['price_list_default_discount_pct_local' => 20, 'price_list_default_discount_pct_staff' => 20])
            ->fillForm(['price_list_default_discount_pct_staff' => 30])->call('save')->assertHasNoFormErrors();
        $this->assertSame(30, (int) Settings::get('price_list_default_discount_pct_staff'));
        $this->assertSame(['cents' => 700, 'set' => false], $this->batch->fresh()->priceFor(PriceList::STAFF, 'gram'));

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $this->actingAs($manager);
        $this->assertFalse(ManageSettings::canAccess(), 'a manager never reaches the org settings');
    }

    // --- Discounts -------------------------------------------------------------------------------------------------------------

    public function test_a_new_staff_or_local_discount_cannot_be_created_but_an_existing_one_stays_editable(): void
    {
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => 'Personal', 'kind' => DiscountKind::STAFF->value, 'value_pct' => 20])
            ->call('create')->assertHasFormErrors(['kind']);
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => 'Locales', 'kind' => DiscountKind::LOCAL->value, 'value_pct' => 10])
            ->call('create')->assertHasFormErrors(['kind']);
        $this->assertSame(0, Discount::query()->count());

        $existing = $this->staffDiscount();
        Livewire::test(EditDiscount::class, ['record' => $existing->id])
            ->fillForm(['name' => 'Plantilla 2026'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(DiscountKind::STAFF, $existing->fresh()->kind);
    }

    // --- Member page -------------------------------------------------------------------------------------------------------

    public function test_the_member_page_says_a_percentage_does_not_apply_on_a_list_tier(): void
    {
        $discount = $this->staffDiscount(2000);
        $personal = $this->member(PriceList::STAFF);
        (new AssignMemberDiscount)->handle($personal, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Plantilla']);
        $standard = $this->member(PriceList::STANDARD);
        (new AssignMemberDiscount)->handle($standard, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Plantilla']);

        // The staff discount covers the bar too (BOTH): it still applies there, so it says «a la flor».
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $personal, 'pageClass' => ViewMember::class])
            ->assertSee('No se aplica a la flor: su tarifa paga precios «Personal»');
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $standard, 'pageClass' => ViewMember::class])
            ->assertDontSee('No se aplica');

        $flowerOnly = Discount::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Terapia', 'kind' => DiscountKind::THERAPEUTIC, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 1500, 'applies_to' => DiscountAppliesTo::GENETIC, 'active' => true]);
        (new AssignMemberDiscount)->handle($personal, $this->owner, ['discount_id' => $flowerOnly->id, 'reason' => 'Terapéutico']);
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $personal->fresh(), 'pageClass' => ViewMember::class])
            ->assertSee('No se aplica: su tarifa paga precios «Personal»');
    }

    // --- Salud del sistema -------------------------------------------------------------------------------------------------

    public function test_system_health_lists_the_members_still_on_a_staff_discount_and_the_tiers_that_had_one(): void
    {
        $discount = $this->staffDiscount();
        $member = $this->member(PriceList::STANDARD);
        $member->forceFill(['first_name' => 'Rosa', 'last_name' => 'Vidal'])->save();
        (new AssignMemberDiscount)->handle($member, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Plantilla']);
        MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Terapéutico', 'discount_bp' => 1000]);
        // 383 — someone already on a Personal tier is done: their % no longer applies, so they are not listed.
        $moved = $this->member(PriceList::STAFF);
        $moved->forceFill(['first_name' => 'Pablo', 'last_name' => 'Ruiz'])->save();
        (new AssignMemberDiscount)->handle($moved, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Plantilla']);

        Livewire::test(SystemHealth::class)
            ->assertSeeHtml('data-health-price-list-moves')
            ->assertSee('1 socio tiene un descuento de personal asignado: ponlo en una tarifa con precios «Personal».')
            ->assertSee('Rosa Vidal')->assertDontSee('Pablo Ruiz')
            ->assertSeeHtml(e(ViewMember::getUrl(['record' => $member->id])))
            ->assertSee('Terapéutico');
    }

    // --- New batch: the previous batch's prices ----------------------------------------------------------------------------

    public function test_a_new_batch_of_the_strain_at_the_sede_starts_with_the_previous_batchs_prices_and_says_so(): void
    {
        $this->batch->forceFill(['staff_price_per_eighth_cents' => 2200])->save();

        Livewire::test(CreateBatch::class)
            ->fillForm(['location_id' => $this->sede->id, 'product_type' => $this->flower->product_type->value])
            ->fillForm(['genetic_id' => $this->flower->id])
            ->assertFormSet(['sale_price_eur' => '10.00', 'price_per_eighth_eur' => '30.00', 'local_price_eur' => '8.80', 'local_eighth_eur' => null,
                'staff_price_eur' => null, 'staff_eighth_eur' => '22.00'])
            ->assertSee('Precios copiados del lote anterior (#3)')
            ->fillForm(['grams' => '100', 'cost_per_gram_eur' => '3', 'local_price_eur' => '8.50'])
            ->call('create')->assertHasNoFormErrors();

        $new = Batch::query()->where('genetic_id', $this->flower->id)->latest('created_at')->latest('id')->first();
        $this->assertNotSame($this->batch->id, $new->id);
        $this->assertSame(850, (int) $new->getRawOriginal('local_price_per_gram_cents'));
        $this->assertSame(2200, (int) $new->getRawOriginal('staff_price_per_eighth_cents'));
        $this->assertNull($new->getRawOriginal('staff_price_per_gram_cents'));
    }

    // --- Precio: the six fields ----------------------------------------------------------------------------------------------

    public function test_the_precio_action_sets_the_local_and_personal_prices_and_a_blank_one_goes_back_to_the_default(): void
    {
        Livewire::test(ListBatches::class)
            ->mountTableAction('price', $this->batch)
            ->assertTableActionDataSet(['rate_eur' => 10.0, 'local_price_eur' => 8.8, 'staff_price_eur' => null])
            ->setTableActionData(['local_price_eur' => '', 'staff_price_eur' => '7.00', 'staff_eighth_eur' => '22.00'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        $batch = $this->batch->fresh();
        $this->assertNull($batch->getRawOriginal('local_price_per_gram_cents'));
        $this->assertSame(700, (int) $batch->getRawOriginal('staff_price_per_gram_cents'));
        $this->assertSame(2200, (int) $batch->getRawOriginal('staff_price_per_eighth_cents'));
        $this->assertSame(['cents' => 800, 'set' => false], $batch->priceFor(PriceList::LOCAL, 'gram'));
    }

    // --- Links -------------------------------------------------------------------------------------------------------------

    public function test_the_sedes_badge_and_lotes_link_to_the_pricing_screen(): void
    {
        Livewire::test(ListLocations::class)->assertSeeHtml(e(PreciosSede::getUrl(['sede' => $this->sede->id])));
        Livewire::test(ListBatches::class)->assertActionVisible('sedePrices')->assertActionHasUrl('sedePrices', PreciosSede::getUrl());
    }

    // --- Counter -----------------------------------------------------------------------------------------------------------

    public function test_the_counter_shows_the_list_rate_and_name_on_the_line_the_member_chip_and_the_receipt(): void
    {
        $member = $this->member(PriceList::LOCAL);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);

        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)
            ->assertSeeHtml('data-member-price-list="LOCAL"')
            ->call('chooseGenetic', $this->flower->id)
            ->assertSeeHtml('data-active-price-list')->assertSee(Money::fromCents(880)->formatted().' / g')
            ->set('weightInput', '2')->call('addLine')
            ->assertSeeHtml('data-line-price-list')
            ->assertSee(Money::fromCents(880)->formatted().'/g')
            ->assertSee(Money::fromCents(1760)->formatted());

        $standard = $this->member(PriceList::STANDARD);
        Livewire::test(DispensaryPos::class)->call('selectMember', $standard->id)->assertDontSeeHtml('data-member-price-list');

        // 383 — the basket preview measures a list line's discount exactly as the stored line: 3.5 g at the Local 3.5 g price
        // (30.00 − 20 % = 24.00, Local 3.5 g blank) against the standard 30.00 → 6.00, not 3.5 × (10.00 − 8.80).
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->flower->id)->set('weightInput', '3.5')->call('addLine');
        $view = new \ReflectionMethod(DispensaryPos::class, 'basketView');
        $row = $view->invoke($pos->instance(), $member, $this->sede)[0];
        $this->assertSame([2400, 600], [$row['total_cents'], $row['discount_cents']]);

        $d = (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $this->flower->id, 'batch_id' => $this->batch->id, 'grams_cg' => 200]],
            ['operator_id' => $this->owner->id]);
        $this->get(route('counter.pos.receipt', $d))->assertOk()
            ->assertSee(Money::fromCents(880)->formatted().'/g')->assertSee('· Local')
            ->assertDontSee(Money::fromCents(1000)->formatted().'/g');
    }
}
