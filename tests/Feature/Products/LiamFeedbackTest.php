<?php

namespace Tests\Feature\Products;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\AnonymiseMember;
use App\Actions\Pricing\ResolvePrice;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Exceptions\DispensationBlockedException;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterLastSale;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 347 — Liam (staff, after loading all his stock): "Strains filter by location. Vape category for products doesn't
 * seem to be used… Staff discount: how to apply to individuals when dispensing to themselves. Also remove the publish
 * toggle on genetics… Landing page after dispensing to return to the main page."
 */
class LiamFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

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
        app(ActiveScope::class)->setLocation(null);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($this->owner);
    }

    private function batch(Genetic $genetic, Location $at, int $cg = 5000, int $pricePerGram = 1000): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $at->id,
            'remaining_cg' => $cg, 'initial_cg' => max($cg, 1), 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => $pricePerGram, 'expires_on' => now()->addYear()]);
    }

    private function member(Location $at): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 10000, 'monthly_limit_cg' => 100000, 'photo_path' => 'x.jpg']);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $at->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    // --- 1. Product type and vapes ----------------------------------------------------------------------------------------------

    public function test_the_strain_form_has_no_default_type_and_refuses_to_save_without_one(): void
    {
        Livewire::test(CreateGenetic::class)
            ->assertSchemaStateSet(['product_type' => null])
            ->fillForm(['name' => 'Lemon Haze'])
            ->call('create')
            ->assertHasFormErrors(['product_type' => 'required']);
        $this->assertSame(0, Genetic::query()->count());
    }

    public function test_a_name_that_says_vape_with_type_flower_shows_the_hint(): void
    {
        Livewire::test(CreateGenetic::class)
            ->fillForm(['name' => 'Lemon vape 1 ml', 'product_type' => ProductType::FLOWER->value])
            ->assertSeeHtml('data-vape-hint')
            ->assertSee(__('¿Es un vapeador? Elige «Vapeador» para que se dispense por unidad.'))
            ->fillForm(['product_type' => ProductType::VAPE->value])
            ->assertDontSeeHtml('data-vape-hint');
    }

    public function test_a_bar_product_named_like_a_cannabis_vape_warns_and_needs_the_audited_accessory_confirmation(): void
    {
        app(ActiveScope::class)->setLocation($this->centro->id);
        $page = Livewire::test(CreateArticle::class)
            ->fillForm(['location_id' => [$this->centro->id], 'name' => 'THC vape 1 g', 'price_eur' => '20', 'stock' => 0])
            ->assertSeeHtml('data-vape-bar-warning')
            ->call('create')
            ->assertHasFormErrors(['accessory_confirmed']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());

        $page->fillForm(['accessory_confirmed' => true])->call('create')->assertHasNoFormErrors();
        $article = Article::query()->withoutGlobalScopes()->sole();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'article.accessory_confirmed')->where('auditable_id', $article->id)->count());
    }

    public function test_find_vape_like_lists_them_and_changes_nothing(): void
    {
        Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Lemon vape', 'product_type' => ProductType::FLOWER]);
        Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia', 'product_type' => ProductType::FLOWER, 'published' => false]);
        Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Vape THC']);
        $before = Genetic::query()->orderBy('id')->get(['id', 'product_type', 'published'])->toArray();

        $this->artisan('csc:find-vape-like')
            ->expectsOutputToContain('Lemon vape')
            ->expectsOutputToContain('Vape THC')
            ->expectsOutputToContain('No publicadas: 1')
            ->assertSuccessful();

        $this->assertSame($before, Genetic::query()->orderBy('id')->get(['id', 'product_type', 'published'])->toArray());
    }

    // --- 2. Strains by sede --------------------------------------------------------------------------------------------------------

    public function test_the_sede_filter_shows_strains_in_stock_there_and_the_sedes_column_lists_them(): void
    {
        $centro = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Solo Centro']);
        $norte = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Solo Norte']);
        $both = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Ambas']);
        $empty = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Recién creada']);
        $this->batch($centro, $this->centro);
        $this->batch($norte, $this->norte);
        $this->batch($both, $this->centro);
        $this->batch($both, $this->norte);
        $this->batch($norte, $this->centro, cg: 0); // an emptied batch at Centro is not stock there

        Livewire::test(ListGenetics::class)
            ->filterTable('sede', [$this->centro->id])
            ->assertCanSeeTableRecords([$centro, $both, $empty]) // a strain with no stock anywhere belongs to no sede yet
            ->assertCanNotSeeTableRecords([$norte])
            ->assertTableColumnStateSet('in_stock_at', 'Sede Centro · Sede Norte', $both);
    }

    public function test_with_one_sede_chosen_in_the_panel_the_filter_starts_on_it(): void
    {
        $norte = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Solo Norte']);
        $this->batch($norte, $this->norte);
        app(ActiveScope::class)->setLocation($this->centro->id);

        Livewire::test(ListGenetics::class)
            ->assertSet('tableFilters.sede.values', [$this->centro->id])
            ->assertCanNotSeeTableRecords([$norte]);

        app(ActiveScope::class)->setLocation(null);
        Livewire::test(ListGenetics::class)->assertSet('tableFilters.sede.values', []);
    }

    // --- 3. Publishing -----------------------------------------------------------------------------------------------------------

    public function test_the_form_has_no_publish_toggle_new_strains_publish_and_existing_unpublished_stay(): void
    {
        Livewire::test(CreateGenetic::class)->assertFormFieldDoesNotExist('published')
            ->fillForm(['name' => 'Nueva', 'product_type' => ProductType::FLOWER->value])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Genetic::query()->where('name', 'Nueva')->sole()->published);

        $hidden = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Oculta', 'published' => false]);
        Livewire::test(EditGenetic::class, ['record' => $hidden->id])->fillForm(['description' => 'Editada'])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($hidden->fresh()->published);
    }

    // --- 4. After a sale ----------------------------------------------------------------------------------------------------------

    private function counterAt(Location $sede): void
    {
        app(ActiveScope::class)->setLocation($sede->id);
        session(['counter.location_id' => $sede->id]);
        (new OpenTill)->handle($sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);
    }

    private function sell(Location $sede): Testable
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        $batch = $this->batch($genetic, $sede);
        $member = $this->member($sede);

        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->set('cashTendered', '20')
            ->call('commitDispensation');
    }

    public function test_by_default_the_counter_goes_home_after_recording_with_the_last_sale_line_and_a_working_void(): void
    {
        $this->counterAt($this->centro);
        $pos = $this->sell($this->centro);

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertStringContainsString(route('counter.home'), json_encode($pos->effects['xjs'] ?? [], JSON_UNESCAPED_SLASHES) ?: '', 'no trip home was scheduled');
        $sale = CounterLastSale::current($this->centro->id);
        $this->assertNotNull($sale);

        $home = Livewire::test(CounterHome::class)->assertSeeHtml('data-hub-last-sale')->assertSeeHtml('data-last-sale-void');
        $home->set('voidReason', 'Error de peso')->call('voidLast');
        $this->assertSame('VOIDED', Dispensation::query()->withoutGlobalScopes()->sole()->status->value);
        $this->assertNull(CounterLastSale::current($this->centro->id));
    }

    public function test_the_hub_line_goes_after_two_minutes_or_a_lock(): void
    {
        $this->counterAt($this->centro);
        $this->sell($this->centro);
        $this->travel(CounterLastSale::SECONDS + 5)->seconds();
        Livewire::test(CounterHome::class)->assertDontSeeHtml('data-hub-last-sale');
    }

    public function test_stay_keeps_todays_behaviour_and_new_member_empties_the_dispensary(): void
    {
        $this->counterAt($this->centro);
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->centro->id);
        $stay = $this->sell($this->centro);
        $this->assertNotNull($stay->get('memberId'));
        $this->assertStringNotContainsString(route('counter.home'), json_encode($stay->effects['xjs'] ?? [], JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertNull(CounterLastSale::current($this->centro->id));

        Settings::set('after_recording', 'new_member', SettingType::STRING, (string) $this->centro->id);
        $next = $this->sell($this->centro);
        $this->assertNull($next->get('memberId'));
        $this->assertNotNull($next->get('lastDispensationId'), 'the last-sale line stays');
    }

    // --- 5. Staff discount and serving yourself ----------------------------------------------------------------------------------

    private function staffDiscount(): Discount
    {
        $discount = Discount::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Personal 20 %', 'kind' => DiscountKind::STAFF,
            'mode' => DiscountMode::PERCENT, 'value_bp' => 2000, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $discount->locations()->sync([$this->centro->id, $this->norte->id]);

        return $discount;
    }

    public function test_the_staff_discount_applies_by_itself_to_a_member_linked_to_an_active_staff_account(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = $this->batch($genetic, $this->centro);
        $member = $this->member($this->centro);
        $staff = User::factory()->create(['active' => true]);
        $staff->assignRole(Role::STAFF->value);
        $staff->forceFill(['member_id' => $member->id])->save();
        $discount = $this->staffDiscount();

        $price = fn (): ?array => (new ResolvePrice)->forBatch($batch, $genetic, $this->centro, $member->fresh())->discount;

        $this->assertNull($price(), 'no club setting: nothing changes');

        Settings::set('staff_discount_id', $discount->id, SettingType::STRING);
        $this->assertSame(2000, $price()['value_bp'] ?? null);

        $staff->forceFill(['active' => false])->save();
        $this->assertNull($price(), 'a deactivated staff account: no discount');
    }

    public function test_serving_your_own_member_record_is_flagged_and_can_be_blocked_per_sede(): void
    {
        $this->counterAt($this->centro);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = $this->batch($genetic, $this->centro);
        $member = $this->member($this->centro);
        $this->owner->forceFill(['member_id' => $member->id])->save();
        CounterOperator::set($this->owner->fresh());

        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)
            ->assertSeeHtml('data-self-serving')->assertSee(__('Te estás atendiendo a ti mismo'));

        $line = [['genetic_id' => $genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 100]];
        $own = (new CommitDispensation)->handle($member, $this->centro, $line, ['operator_id' => $this->owner->id]);
        $this->assertTrue($own->self_dispensed);

        Settings::set('block_self_dispensation', true, SettingType::BOOL, (string) $this->centro->id);
        try {
            (new CommitDispensation)->handle($member, $this->centro, $line, ['operator_id' => $this->owner->id]);
            $this->fail('self-dispensation went through with the block on');
        } catch (DispensationBlockedException) {
        }

        $colleague = User::factory()->create();
        $colleague->assignRole(Role::STAFF->value);
        $served = (new CommitDispensation)->handle($member, $this->centro, $line, ['operator_id' => $colleague->id]);
        $this->assertFalse($served->self_dispensed);
    }

    public function test_erasing_the_member_cuts_the_staff_link_but_keeps_the_account(): void
    {
        $member = $this->member($this->centro);
        $staff = User::factory()->create(['active' => true]);
        $staff->forceFill(['member_id' => $member->id])->save();

        (new AnonymiseMember)->handle($member);

        $this->assertNull($staff->fresh()->member_id);
        $this->assertTrue($staff->fresh()->active);
    }

    public function test_linking_a_member_record_is_audited(): void
    {
        $member = $this->member($this->centro);
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        Livewire::test(EditUser::class, ['record' => $staff->id])
            ->fillForm(['member_id' => $member->id])->call('save')->assertHasNoFormErrors();

        $this->assertSame($member->id, $staff->fresh()->member_id);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'user.member_linked')->count());
    }
}
