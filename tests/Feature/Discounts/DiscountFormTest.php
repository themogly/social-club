<?php

namespace Tests\Feature\Discounts;

use App\Actions\Pricing\ResolveArticleDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\CategoryAppliesTo;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\Role;
use App\Filament\Resources\Discounts\DiscountResource;
use App\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Models\Article;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 168 — the discount form had two failure modes and BOTH were invisible: it refused without
 * saying so, or accepted something meaningless without saying so.
 *
 *  - `name`, `kind` and `mode` were `->required()`, so the browser's own constraint check refused the
 *    primary button's submit. 0 Livewire requests, 0 error nodes, nothing turned red. The secondary
 *    "Crear y crear otro" button (wire:click, bypassing native submission) showed three errors on the
 *    same empty form.
 *  - `value_pct`/`value_eur` had no required rule and no visibility condition, so a 0 % discount could
 *    be created: active, assignable, listed, and taking nothing off anything for ever.
 *
 * And the fixed-amount mode was reachable from that form into a real pricing bug: candidates were
 * ranked by what each saves on ONE GRAM while the winner was applied to the WHOLE subtotal.
 */
class DiscountFormTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->actingAs($this->owner());
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    // --- a. A click on the primary button always answers -------------------------------------------

    public function test_the_form_no_longer_relies_on_native_constraint_validation(): void
    {
        // The regression test for the reported symptom. The app's own validation always worked — what
        // failed was that the browser refused the submit before the app was asked, so the fix has to be
        // asserted on the rendered form element, which is where `novalidate` lives.
        Livewire::test(CreateDiscount::class)
            ->assertSee('novalidate', escape: false);
    }

    public function test_an_empty_submit_produces_visible_field_errors(): void
    {
        Livewire::test(CreateDiscount::class)
            ->call('create')
            ->assertHasFormErrors(['name', 'kind', 'value_pct']);

        $this->assertSame(0, Discount::query()->withoutGlobalScopes()->count());
    }

    public function test_a_select_left_on_its_placeholder_is_reported(): void
    {
        // The reported case: `kind` opens on "Seleccione una opción" and nothing said so.
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => 'Prueba', 'value_pct' => 10])
            ->call('create')
            ->assertHasFormErrors(['kind']);
    }

    // --- b. A discount must have a value ------------------------------------------------------------

    public function test_a_discount_cannot_be_created_with_an_empty_percentage(): void
    {
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => 'Prueba', 'kind' => DiscountKind::LOCAL->value, 'value_pct' => null])
            ->call('create')
            ->assertHasFormErrors(['value_pct']);

        $this->assertSame(0, Discount::query()->withoutGlobalScopes()->count());
    }

    public function test_a_discount_cannot_be_created_worth_zero_percent(): void
    {
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => 'Prueba', 'kind' => DiscountKind::LOCAL->value, 'value_pct' => 0])
            ->call('create')
            ->assertHasFormErrors(['value_pct']);

        $this->assertSame(0, Discount::query()->withoutGlobalScopes()->count());
    }

    public function test_a_valid_percentage_stores_the_right_basis_points(): void
    {
        $this->createDiscount('Diez', 10);
        $this->assertSame(1000, Discount::query()->withoutGlobalScopes()->sole()->value_bp);

        Discount::query()->withoutGlobalScopes()->forceDelete();

        // Pins the half-up rounding at the conversion boundary.
        $this->createDiscount('Doce y medio', 12.5);
        $this->assertSame(1250, Discount::query()->withoutGlobalScopes()->sole()->value_bp);
    }

    // --- c. Percentages only -------------------------------------------------------------------------

    public function test_no_fixed_amount_discount_can_be_created_through_the_form(): void
    {
        // Including by a crafted request: mode and applies_to are stamped, never read from the payload.
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => 'Intento', 'kind' => DiscountKind::LOCAL->value, 'value_pct' => 10])
            ->set('data.mode', DiscountMode::FIXED->value)
            ->set('data.value_eur', 3)
            ->set('data.applies_to', DiscountAppliesTo::ARTICLE->value)
            ->call('create')
            ->assertHasNoFormErrors();

        $discount = Discount::query()->withoutGlobalScopes()->sole();
        $this->assertSame(DiscountMode::PERCENT, $discount->mode);
        $this->assertSame(DiscountAppliesTo::GENETIC, $discount->applies_to);
        $this->assertSame(1000, $discount->value_bp);
        $this->assertNull($discount->value_cents);
    }

    public function test_the_form_offers_no_mode_choice_at_all(): void
    {
        // Not a one-option dropdown either — that would be the same mistake as two money fields.
        Livewire::test(CreateDiscount::class)
            ->assertDontSee('data.mode', escape: false)
            ->assertDontSee(__('Importe fijo'));
    }

    // --- The overcharge, as a regression test ---------------------------------------------------------

    public function test_a_member_is_never_charged_more_than_the_best_discount_available(): void
    {
        // The measured case: rate €10/g, 10 g, subtotal €100, candidates 10% and €3 fixed. Ranked on one
        // gram the €3 beat the 10% (300 > 100 cents), and the €3 was then applied to the whole subtotal
        // — €7.00 more than the member should have paid.
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'genetic_id' => $genetic->getKey(), 'price_per_gram_cents' => 1000,
        ]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        $percent = $this->legacyDiscount('10 %', DiscountMode::PERCENT, valueBp: 1000);
        $fixed = $this->legacyDiscount('3 € fijo', DiscountMode::FIXED, valueCents: 300);
        $member->memberDiscounts()->create(['organisation_id' => $this->org->id, 'discount_id' => $percent->getKey()]);
        $member->memberDiscounts()->create(['organisation_id' => $this->org->id, 'discount_id' => $fixed->getKey()]);

        $price = (new ResolvePrice)->forGenetic($genetic->fresh(), $this->location, $member->fresh());

        $subtotal = 10 * 1000;                       // 10 g at €10/g = €100.00
        $this->assertSame(1000, $price->discountAmount($subtotal), 'The member must get the 10 %, not the €3.');
        // Before this branch the €3 fixed won and discountAmount() returned 300 — €7.00 too little off.
        $this->assertNotSame(300, $price->discountAmount($subtotal));
    }

    public function test_a_legacy_fixed_amount_row_still_prices_when_it_is_the_only_candidate(): void
    {
        // Nothing can author one any more, but an existing member's discount must keep working.
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'genetic_id' => $genetic->getKey(), 'price_per_gram_cents' => 1000,
        ]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $member->memberDiscounts()->create([
            'organisation_id' => $this->org->id,
            'discount_id' => $this->legacyDiscount('3 € fijo', DiscountMode::FIXED, valueCents: 300)->getKey(),
        ]);

        $price = (new ResolvePrice)->forGenetic($genetic->fresh(), $this->location, $member->fresh());

        $this->assertSame(300, $price->discountAmount(10 * 1000));
    }

    // --- d. Flower only ---------------------------------------------------------------------------------

    public function test_a_new_discount_is_genetic_scoped_and_the_bar_resolver_ignores_it(): void
    {
        $this->createDiscount('Nuevo', 10);
        $discount = Discount::query()->withoutGlobalScopes()->sole();
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $member->memberDiscounts()->create(['organisation_id' => $this->org->id, 'discount_id' => $discount->getKey()]);

        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'price_cents' => 1000,
        ]);

        $this->assertSame(DiscountAppliesTo::GENETIC, $discount->applies_to);
        // A GENETIC-scoped discount is invisible to the bar resolver, so the bar takes nothing off.
        $this->assertSame(0, (new ResolveArticleDiscount)->bpFor($member->fresh(), $this->location));
        $this->assertSame(0, (new ResolveArticleDiscount)->discountCents($article->price_cents->cents, 0));
    }

    public function test_the_form_offers_no_applies_to_choice(): void
    {
        Livewire::test(CreateDiscount::class)->assertDontSee('data.applies_to', escape: false);
    }

    // --- e. The category picker -------------------------------------------------------------------------

    public function test_the_category_picker_offers_only_genetic_categories(): void
    {
        $genetic = Category::factory()->create(['organisation_id' => $this->org->id, 'applies_to' => CategoryAppliesTo::GENETIC, 'name' => 'Flores']);
        $article = Category::factory()->create(['organisation_id' => $this->org->id, 'applies_to' => CategoryAppliesTo::ARTICLE, 'name' => 'Bebidas']);

        $options = Livewire::test(CreateDiscount::class)
            ->instance()
            ->getSchema('form')
            ->getComponent(fn ($component): bool => $component instanceof Select
                && $component->getName() === 'category_id')
            ->getOptions();

        $this->assertArrayHasKey($genetic->getKey(), $options);
        $this->assertArrayNotHasKey($article->getKey(), $options, 'A bar category must not be offered on a flower-only discount.');
    }

    // --- Editing round-trips ------------------------------------------------------------------------------

    public function test_editing_a_discount_leaves_its_value_untouched(): void
    {
        $discount = $this->legacyDiscount('Original', DiscountMode::PERCENT, valueBp: 1500);

        Livewire::test(EditDiscount::class, ['record' => $discount->getRouteKey()])
            ->fillForm(['name' => 'Renombrado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $discount->fresh();
        $this->assertSame('Renombrado', $fresh->name);
        $this->assertSame(1500, $fresh->value_bp);
    }

    public function test_editing_a_legacy_bar_discount_never_restamps_its_scope(): void
    {
        // The hazard: EditDiscount shares normalise() with create. Stamping there would silently convert
        // every legacy BOTH/ARTICLE row the moment somebody corrected its name — taking the bar's
        // discounts out with it.
        $discount = $this->legacyDiscount('Personal', DiscountMode::PERCENT, valueBp: 1000, appliesTo: DiscountAppliesTo::BOTH);

        Livewire::test(EditDiscount::class, ['record' => $discount->getRouteKey()])
            ->fillForm(['name' => 'Personal (bar)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(DiscountAppliesTo::BOTH, $discount->fresh()->applies_to);
    }

    public function test_editing_a_legacy_fixed_row_keeps_its_amount(): void
    {
        $discount = $this->legacyDiscount('3 € fijo', DiscountMode::FIXED, valueCents: 300);

        Livewire::test(EditDiscount::class, ['record' => $discount->getRouteKey()])
            ->fillForm(['name' => 'Fijo heredado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $discount->fresh();
        $this->assertSame('Fijo heredado', $fresh->name);
        $this->assertSame(DiscountMode::FIXED, $fresh->mode);
        $this->assertSame(300, $fresh->value_cents->cents);
    }

    // --- Denial ------------------------------------------------------------------------------------------

    public function test_a_staff_user_cannot_reach_the_discount_form(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $this->actingAs($staff);

        $this->get(DiscountResource::getUrl('create'))->assertForbidden();
    }

    // --- Fixtures -----------------------------------------------------------------------------------------

    private function createDiscount(string $name, float $pct): void
    {
        Livewire::test(CreateDiscount::class)
            ->fillForm(['name' => $name, 'kind' => DiscountKind::LOCAL->value, 'value_pct' => $pct])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    /** A row as it could exist before this branch — created directly, since the form can no longer author one. */
    private function legacyDiscount(
        string $name,
        DiscountMode $mode,
        ?int $valueBp = null,
        ?int $valueCents = null,
        DiscountAppliesTo $appliesTo = DiscountAppliesTo::GENETIC,
    ): Discount {
        return Discount::factory()->create([
            'organisation_id' => $this->org->id,
            'name' => $name,
            'kind' => DiscountKind::LOCAL,
            'mode' => $mode,
            'value_bp' => $valueBp,
            'value_cents' => $valueCents,
            'applies_to' => $appliesTo,
            'active' => true,
        ]);
    }
}
