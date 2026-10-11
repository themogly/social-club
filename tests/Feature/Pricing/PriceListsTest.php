<?php

namespace Tests\Feature\Pricing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Stock\TransferBatch;
use App\Enums\BatchStatus;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\LocationKind;
use App\Enums\MemberStatus;
use App\Enums\PriceList;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Reports\DiscountsReport;
use App\ViewModels\Reports\LossesReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 382 — Arron: "when input a price can input for staff price, local and tourist price rather than setting a % cos it's
 * not correct for everything." Ben: "Each batch is gonna need a local and staff price, both for gram and 3.5 … attached to a
 * tier … standard / local / staff (just them 3 constants) … if no price is set it defaults to 20 percent for now."
 */
class PriceListsTest extends TestCase
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
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL);
        Settings::set('discount_rounding', 'none', SettingType::STRING);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        // Standard 10.00/g and 30.00 per 3.5 g; Local 8.00/g and 25.00 per 3.5 g; Personal blank.
        $this->batch = $this->batch($this->flower, ['price_per_gram_cents' => 1000, 'price_per_eighth_cents' => 3000,
            'local_price_per_gram_cents' => 800, 'local_price_per_eighth_cents' => 2500]);
    }

    /** @param  array<string, mixed>  $prices */
    private function batch(Genetic $genetic, array $prices, ?Location $at = null): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => ($at ?? $this->sede)->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'cost_per_gram_cents' => 300] + $prices);
    }

    private function member(PriceList $list, bool $therapeutic = false): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000, 'is_therapeutic' => $therapeutic]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => $list->label(), 'discount_bp' => 0, 'price_list' => $list]);
        (new EnrolMembership)->handle($member, $this->sede, $tier, ['actor' => $this->owner, 'fee_cents' => 0]);

        return $member;
    }

    /** @return array{total: int, discount: int, kind: ?string} */
    private function buy(Member $member, int $cg, ?Batch $batch = null, array $line = []): array
    {
        $batch ??= $this->batch;
        $d = (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $batch->genetic_id, 'batch_id' => $batch->id] + ($line ?: ['grams_cg' => $cg])],
            ['operator_id' => $this->owner->id]);
        $lines = $d->lines()->withoutGlobalScopes()->get();

        return ['total' => $d->total_cents->cents, 'discount' => (int) $lines->sum(fn ($l) => $l->discount_cents->cents), 'kind' => $lines->first()->discount_kind];
    }

    // --- 1. List prices ------------------------------------------------------------------------------------------------------

    public function test_each_member_pays_their_lists_price_and_a_blank_list_is_the_standard_less_twenty_percent(): void
    {
        $local = $this->member(PriceList::LOCAL);
        $this->assertSame(1600, $this->buy($local, 200)['total']);
        $this->assertSame(2500, $this->buy($local, 350)['total']);

        $staff = $this->member(PriceList::STAFF);
        $this->assertSame(1600, $this->buy($staff, 200)['total']);   // 10.00 − 20 %
        $this->assertSame(2400, $this->buy($staff, 350)['total']);   // 30.00 − 20 %

        $this->assertSame(2000, $this->buy($this->member(PriceList::STANDARD), 200)['total']);

        $this->assertSame(['cents' => 800, 'set' => true], $this->batch->priceFor(PriceList::LOCAL, 'gram'));
        $this->assertSame(['cents' => 800, 'set' => false], $this->batch->priceFor(PriceList::STAFF, 'gram'));
    }

    // --- 2. The default % ------------------------------------------------------------------------------------------------------

    public function test_the_default_percentage_is_a_setting_per_list(): void
    {
        Settings::set('price_list_default_discount_pct_staff', 30, SettingType::INT);
        $this->assertSame(1400, $this->buy($this->member(PriceList::STAFF), 200)['total']);
    }

    // --- 3. The list price overrides discounts (Ben, 11 Oct — prompt 383; 382 had the lower win) ------------------------------

    private function discountFor(Member $member, DiscountKind $kind, int $bp): void
    {
        $discount = Discount::factory()->create(['organisation_id' => $this->org->id, 'kind' => $kind, 'mode' => DiscountMode::PERCENT,
            'value_bp' => $bp, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $discount->locations()->sync([$this->sede->id]);
        (new AssignMemberDiscount)->handle($member, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Prueba']);
    }

    public function test_a_set_list_price_beats_a_cheaper_percentage(): void
    {
        $this->batch->forceFill(['staff_price_per_gram_cents' => 900])->save();
        $personal = $this->member(PriceList::STAFF);
        $this->discountFor($personal, DiscountKind::STAFF, 1500);

        $bought = $this->buy($personal, 100);
        $this->assertSame(900, $bought['total'], 'the Personal price, not 10.00 − 15 % = 8.50');
        $this->assertSame('STAFF', $bought['kind']);
    }

    public function test_therapeutic_does_not_apply_on_a_list(): void
    {
        $therapeutic = Discount::factory()->create(['organisation_id' => $this->org->id, 'kind' => DiscountKind::THERAPEUTIC, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 1500, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $therapeutic->locations()->sync([$this->sede->id]);
        $member = $this->member(PriceList::STAFF, therapeutic: true);

        $this->assertSame(800, $this->buy($member, 100)['total'], 'Personal blank: 10.00 − 20 %, no therapeutic on top or instead');
        $this->batch->forceFill(['staff_price_per_gram_cents' => 900])->save();
        $this->assertSame(900, $this->buy($member, 100)['total'], 'Personal 9.00, though 10.00 − 15 % would be 8.50');
    }

    public function test_the_eighth_and_unit_prices_follow_the_list_too(): void
    {
        $local = $this->member(PriceList::LOCAL);
        $this->discountFor($local, DiscountKind::CONCESSION, 2000);
        $this->assertSame(2500, $this->buy($local, 350)['total'], 'Local 3.5 g 25.00, no concession');

        $edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Gominola', 'product_type' => ProductType::EDIBLE, 'unit_type' => 'UNIT', 'grams_per_unit_cg' => 7]);
        $gummy = Batch::factory()->units(20, 20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $edible->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'price_per_unit_cents' => 400, 'local_price_per_unit_cents' => 300]);
        $this->assertSame(300, $this->buy($local, 0, $gummy, ['units' => 1])['total']);
    }

    public function test_standard_members_keep_their_percentages(): void
    {
        $staff = $this->member(PriceList::STANDARD);
        $this->discountFor($staff, DiscountKind::STAFF, 1000);
        $this->assertSame(900, $this->buy($staff, 100)['total']);

        $therapeutic = Discount::factory()->create(['organisation_id' => $this->org->id, 'kind' => DiscountKind::THERAPEUTIC, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 1500, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $therapeutic->locations()->sync([$this->sede->id]);
        $this->assertSame(850, $this->buy($this->member(PriceList::STANDARD, therapeutic: true), 100)['total']);
    }

    // --- 4. What the line records ---------------------------------------------------------------------------------------------------

    public function test_the_line_records_the_difference_from_standard_as_a_staff_discount_and_losses_shows_it(): void
    {
        $bought = $this->buy($this->member(PriceList::STAFF), 200);
        $this->assertSame(400, $bought['discount'], 'standard 20.00 − charged 16.00');
        $this->assertSame('STAFF', $bought['kind']);

        $lines = (new LossesReport($this->org->id, [$this->sede->id], Period::today($this->sede)))->sections()['mostrador']['lines'];
        $this->assertSame(400, $lines['member_discounts_staff']['cents']);

        // 381's per-person alert counts only the discounts a person CHOOSES (Ben, after 377): a list price is automatic.
        Settings::set('losses_person_discount_alert_cents', 100, SettingType::CENTS);
        $this->assertSame(0, LossesReport::peopleAboveThreshold($this->sede)['count']);
    }

    // --- 5. Unit products -------------------------------------------------------------------------------------------------------------

    public function test_unit_products_have_three_prices_too(): void
    {
        $edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Gominola', 'product_type' => ProductType::EDIBLE, 'unit_type' => 'UNIT', 'grams_per_unit_cg' => 7]);
        $batch = Batch::factory()->units(20, 20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $edible->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'price_per_unit_cents' => 400, 'local_price_per_unit_cents' => 300]);

        $this->assertSame(300, $this->buy($this->member(PriceList::LOCAL), 0, $batch, ['units' => 1])['total']);
        $this->assertSame(320, $this->buy($this->member(PriceList::STAFF), 0, $batch, ['units' => 1])['total']);
    }

    // --- 6. Inheritance --------------------------------------------------------------------------------------------------------------

    public function test_a_new_batch_starts_with_the_previous_batchs_prices_and_a_transfer_copies_them(): void
    {
        $this->batch->forceFill(['staff_price_per_gram_cents' => 700, 'staff_price_per_eighth_cents' => 2200])->save();
        $this->assertSame([
            'price_per_gram_cents' => 1000, 'price_per_eighth_cents' => 3000, 'price_per_unit_cents' => null,
            'local_price_per_gram_cents' => 800, 'local_price_per_eighth_cents' => 2500, 'local_price_per_unit_cents' => null,
            'staff_price_per_gram_cents' => 700, 'staff_price_per_eighth_cents' => 2200, 'staff_price_per_unit_cents' => null,
        ], Batch::previousPricesFor($this->flower->id, $this->sede->id));

        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN, 'name' => 'Almacén']);
        $other = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->owner->locations()->sync([$this->sede->id, $store->id, $other->id]);
        $source = $this->batch($this->flower, ['price_per_gram_cents' => 1000, 'local_price_per_gram_cents' => 850, 'staff_price_per_eighth_cents' => 2100], $store);
        $child = (new TransferBatch)->handle($source, $other, 1000, $this->owner);
        $this->assertSame(850, (int) $child->getRawOriginal('local_price_per_gram_cents'));
        $this->assertSame(2100, (int) $child->getRawOriginal('staff_price_per_eighth_cents'));
    }

    // --- 8. Existing % discounts: kept on a standard tier, ignored on a list tier (383) ----------------------------------------

    public function test_an_existing_staff_percentage_keeps_working_on_a_standard_tier_and_stops_on_a_personal_one(): void
    {
        $standard = $this->member(PriceList::STANDARD);
        $this->discountFor($standard, DiscountKind::STAFF, 2500);
        $this->assertSame(750, $this->buy($standard, 100)['total'], 'as before: 10.00 − 25 %');

        $this->batch->forceFill(['staff_price_per_gram_cents' => 800])->save();
        $personal = $this->member(PriceList::STAFF);
        $this->discountFor($personal, DiscountKind::STAFF, 2500);
        $this->assertSame(800, $this->buy($personal, 100)['total'], 'the Personal price, never the 25 %');
        $this->assertSame(PriceList::STAFF, $personal->fresh()->priceListOverridingDiscounts());
        $this->assertNull($standard->fresh()->priceListOverridingDiscounts());
    }

    // --- 383 §2. A list line's discount is measured against the standard INCLUDING its 3.5 g break ---------------------------

    public function test_a_list_lines_discount_is_measured_against_the_standard_with_its_eighth_break(): void
    {
        // Standard 10.00 / 30.00 per 3.5 g; Local 8.00 / 25.00; Personal blank (8.00 / 24.00).
        $local = $this->member(PriceList::LOCAL);
        $staff = $this->member(PriceList::STAFF);

        $this->assertSame(['total' => 2500, 'discount' => 500, 'kind' => 'LOCAL'], $this->buy($local, 350), 'standard 30.00 − 25.00');
        $this->assertSame(['total' => 2400, 'discount' => 600, 'kind' => 'STAFF'], $this->buy($staff, 350), 'standard 30.00 − 24.00');
        $this->assertSame(['total' => 1600, 'discount' => 400, 'kind' => 'LOCAL'], $this->buy($local, 200));
        // 5 g: standard 30.00 + 1.5 × 10.00 = 45.00; Local 25.00 + 1.5 × 8.00 = 37.00.
        $this->assertSame(['total' => 3700, 'discount' => 800, 'kind' => 'LOCAL'], $this->buy($local, 500));

        $edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Gominola', 'product_type' => ProductType::EDIBLE, 'unit_type' => 'UNIT', 'grams_per_unit_cg' => 7]);
        $gummy = Batch::factory()->units(20, 20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $edible->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(), 'price_per_unit_cents' => 400]);
        $this->assertSame(['total' => 320, 'discount' => 80, 'kind' => 'STAFF'], $this->buy($staff, 0, $gummy, ['units' => 1]));

        $types = collect((new DiscountsReport($this->org->id, [$this->sede->id], Period::today($this->sede)))->tables())->firstWhere('key', 'by_type')->rows;
        $byLabel = collect($types)->pluck('importe', 'tipo');
        $this->assertSame(680, $byLabel[DiscountKind::STAFF->label()], 'Descuentos y ajustes: 6.00 + 0.80');
        $this->assertSame(1700, $byLabel[DiscountKind::LOCAL->label()], '5.00 + 4.00 + 8.00');
    }

    // --- 9. Money in cents, with the 3.5 g break and both roundings ------------------------------------------------------------------

    public function test_the_eighth_break_half_gram_and_whole_euro_rounding_work_on_a_list_price(): void
    {
        Settings::set('charge_rounding_enabled', true, SettingType::BOOL);
        Settings::set('discount_rounding', 'nearest', SettingType::STRING);
        Settings::set('discount_rounding_scope', 'local', SettingType::STRING);
        $this->batch->forceFill(['local_price_per_eighth_cents' => 2540])->save();

        $member = $this->member(PriceList::LOCAL);
        $d = (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $this->flower->id, 'batch_id' => $this->batch->id, 'grams_cg' => 340]],
            ['operator_id' => $this->owner->id, 'charge_rounding' => true]);

        $line = $d->lines()->withoutGlobalScopes()->sole();
        $this->assertSame(350, (int) $line->getRawOriginal('charged_cg'), '3.40 g weighed is charged as 3.5 g');
        $this->assertSame(2500, $d->total_cents->cents, 'the Local 3.5 g price 25.40, rounded to the euro');
        $this->assertSame(0, $d->total_cents->cents % 100);
        // The receipt's line: the list's own rate frozen with the row, and its name in the note.
        $this->assertSame(800, (int) $line->getRawOriginal('list_rate_cents'));
        $this->assertStringContainsString('Local', (string) $line->pricing_note);
    }
}
