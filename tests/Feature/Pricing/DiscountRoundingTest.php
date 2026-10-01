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
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\ZReport;
use App\ViewModels\Reports\DiscountsReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 350 — Aaron: "Local discount should round to a whole number rather than be exact, to save needing so much float.
 * It can be a setting in the admin panel, but on by default."
 */
class DiscountRoundingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Member $member;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function discount(DiscountKind $kind, int $bp = 1000): void
    {
        $discount = Discount::factory()->create(['organisation_id' => $this->org->id, 'kind' => $kind, 'mode' => DiscountMode::PERCENT,
            'value_bp' => $bp, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        $discount->locations()->sync([$this->sede->id]);
        (new AssignMemberDiscount)->handle($this->member, $this->owner, ['discount_id' => $discount->id, 'reason' => 'Prueba']);
    }

    /** One gram at this per-gram price. */
    private function commit(int $pricePerGram, int $grams = 100, array $options = []): Dispensation
    {
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => $pricePerGram, 'expires_on' => now()->addYear()]);

        return (new CommitDispensation)->handle($this->member, $this->sede, [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => $grams]],
            $options + ['operator_id' => $this->owner->id]);
    }

    private function linesAddUp(Dispensation $d): void
    {
        $this->assertSame($d->total_cents->cents, (int) $d->lines()->sum('line_total_cents'), 'the lines do not add up to the rounded total');
    }

    // --- 1–3. The defaults: nearest euro, Local only ---------------------------------------------------------------------------

    public function test_a_21_38_basket_with_a_10_percent_local_discount_charges_19_00(): void
    {
        $this->discount(DiscountKind::LOCAL);
        $d = $this->commit(1069, 200); // 2 g × 10,69 € = 21,38 €; −10 % = 19,24 €

        $this->assertSame(1900, $d->total_cents->cents);
        $this->assertSame(-24, $d->rounding_cents->cents);
        $this->linesAddUp($d);
        $this->get(route('counter.pos.receipt', $d))->assertOk()
            ->assertSee(__('Redondeo (incluido)'))->assertSee(Money::fromCents(-24)->formatted());
    }

    public function test_19_50_rounds_half_up_to_20_00(): void
    {
        $this->discount(DiscountKind::LOCAL);
        $d = $this->commit(2167); // 21,67 − 2,17 = 19,50

        $this->assertSame(2000, $d->total_cents->cents);
        $this->assertSame(50, $d->rounding_cents->cents);
    }

    public function test_a_staff_or_therapeutic_discount_is_not_rounded_by_default(): void
    {
        $this->discount(DiscountKind::STAFF);
        $d = $this->commit(1069, 200);

        $this->assertSame(1924, $d->total_cents->cents);
        $this->assertSame(0, $d->rounding_cents->cents);
    }

    // --- 4–5. The other settings --------------------------------------------------------------------------------------------------

    public function test_any_discount_all_contributions_and_no_rounding(): void
    {
        $this->discount(DiscountKind::STAFF);
        Settings::set('discount_rounding_scope', 'any', SettingType::STRING);
        $this->assertSame(1900, $this->commit(1069, 200)->total_cents->cents, 'any: the staff discount rounds too');

        $this->member->memberDiscounts()->delete();
        Settings::set('discount_rounding_scope', 'all', SettingType::STRING);
        $this->assertSame(2100, $this->commit(1069, 200)->total_cents->cents, 'all: an undiscounted 21,38 rounds to 21,00');

        Settings::set('discount_rounding', 'none', SettingType::STRING);
        $this->assertSame(2138, $this->commit(1069, 200)->total_cents->cents, 'none: exact cents');
    }

    public function test_down_to_the_euro(): void
    {
        $this->discount(DiscountKind::LOCAL);
        Settings::set('discount_rounding', 'down', SettingType::STRING);
        $d = $this->commit(2196); // 21,96 − 2,20 = 19,76

        $this->assertSame(1900, $d->total_cents->cents);
        $this->assertSame(-76, $d->rounding_cents->cents);
    }

    // --- 6–8. Interactions ----------------------------------------------------------------------------------------------------------

    private function pos(): Testable
    {
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->sede->id);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1069, 'expires_on' => now()->addYear()]);

        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '2')->call('addLine');
    }

    public function test_a_combined_visit_is_the_rounded_aportacion_plus_the_drink_and_justo_fills_it(): void
    {
        $this->discount(DiscountKind::LOCAL);
        $drink = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 340, 'stock' => 10]);
        $pos = $this->pos()->call('addBarItem', $drink->id);

        $pos->assertSeeHtml('data-basket-rounding');
        $pos->call('quickCash'); // «Justo»
        // The Local discount here applies to the bar too (10 % off 3,40 = 3,06) — and the bar part is never rounded.
        $this->assertSame(1900 + 306, (int) round_half_up((float) str_replace(',', '.', $pos->get('cashTendered')) * 100));
        $pos->call('commitDispensation');
        $this->assertSame(1900, Dispensation::query()->withoutGlobalScopes()->sole()->total_cents->cents);
    }

    public function test_a_managers_adjustment_to_18_50_is_charged_as_typed(): void
    {
        $this->discount(DiscountKind::LOCAL);
        $pos = $this->pos()->set('priceOverrideEuros', '18,50')->set('priceOverrideReason', 'Producto dañado')
            ->set('cashTendered', '18,50')->call('commitDispensation');

        $d = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame(1850, $d->total_cents->cents);
        $this->assertSame(0, $d->rounding_cents->cents);
    }

    public function test_grams_limits_and_stock_are_identical_with_and_without_rounding(): void
    {
        $this->discount(DiscountKind::LOCAL);
        $rounded = $this->commit(1069, 200);
        Settings::set('discount_rounding', 'none', SettingType::STRING);
        $exact = $this->commit(1069, 200);

        $this->assertSame((int) $exact->lines()->sum('grams_cg'), (int) $rounded->lines()->sum('grams_cg'));
        $this->assertSame(2 * 200, (int) Dispensation::query()->withoutGlobalScopes()->get()->sum(fn (Dispensation $d): int => (int) $d->dispensedGramsCg()));
        $this->assertSame(10000 - 200, Batch::query()->withoutGlobalScopes()->findOrFail($rounded->lines()->first()->batch_id)->remaining_cg->centigrams);
    }

    // --- 9. Reports -----------------------------------------------------------------------------------------------------------------

    public function test_the_discounts_report_and_the_z_report_show_the_rounding(): void
    {
        $this->discount(DiscountKind::LOCAL);
        $session = (new OpenTill)->handle($this->sede, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        $this->commit(1069, 200, ['till_session_id' => $session->id]); // −24
        $this->commit(2167, 100, ['till_session_id' => $session->id]); // +50

        $report = new DiscountsReport($this->org->id, [$this->sede->id], Period::today());
        $byOperator = collect($report->tables())->firstWhere('key', 'by_operator');
        $this->assertSame(26, collect($byOperator->rows)->firstWhere('operator_id', $this->owner->id)['redondeo']);
        $bySede = collect($report->tables())->firstWhere('key', 'rounding_by_sede');
        $this->assertSame(26, $bySede->rows[0]['redondeo']);

        $this->assertSame(26, ZReport::forMany(collect([$session->fresh()]))[$session->id]['rounding']);
    }
}
