<?php

namespace Tests\Feature\Reports;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\AlertType;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\AlertMessage;
use App\Support\Alerts\CurrentAlerts;
use App\Support\Money;
use App\Support\Settings;
use App\ViewModels\Reports\LossesReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ben, after 377: "don't want any percent of sales — just if they've been using a lot of discount". The per-person alert no
 * longer divides by what the person sold: it flags whoever GAVE more than a set amount in the last 7 days through the
 * discounts they choose — price adjustments down and waived fees (291's «discrecional») — sales or none. Member discounts
 * (automatic), rounding and stock are not discounts a person chose.
 */
class PeopleDiscountAlertTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
    }

    private function person(Role $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->sede->id]);

        return $user;
    }

    /** A completed sale by $by, adjusted down from $original when given, with an automatic member discount when given. */
    private function sale(User $by, int $total, ?int $original = null, int $memberDiscount = 0): void
    {
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
            'operator_id' => $by->id, 'total_cents' => $total, 'cash_cents' => $total, 'wallet_cents' => 0, 'original_total_cents' => $original,
            'price_override_by' => $original !== null ? $by->id : null, 'price_override_reason' => $original !== null ? 'Amigo' : null,
            'status' => DispensationStatus::COMPLETED, 'dispensed_at' => now(),
        ]);
        DispensationLine::factory()->create(['dispensation_id' => $d->id, 'grams_cg' => 100, 'charged_cg' => 100, 'price_per_gram_cents' => 1000,
            'discount_cents' => $memberDiscount, 'discount_kind' => $memberDiscount > 0 ? 'LOCAL' : null, 'line_total_cents' => $total]);
    }

    private function waiver(User $by, int $cents): void
    {
        $membership = Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => Member::factory()->create(['organisation_id' => $this->org->id])->id,
            'location_id' => $this->sede->id, 'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id]);
        MembershipFeePayment::factory()->create(['membership_id' => $membership->id, 'amount_cents' => $cents, 'method' => FeePaymentMethod::WAIVED,
            'reason' => 'Socio fundador', 'recorded_by' => $by->id, 'paid_at' => now()]);
    }

    /** @return array<string, true> who is flagged at the sede, by name */
    private function flagged(): array
    {
        $people = LossesReport::peopleAboveThreshold($this->sede);

        return array_fill_keys(User::query()->whereIn('id', $people['people'])->pluck('name')->all(), true);
    }

    public function test_whoever_gives_more_than_the_amount_in_discounts_is_flagged_whatever_they_sold(): void
    {
        $ana = $this->person(Role::STAFF, 'Ana');
        $this->sale($ana, 54000, original: 60000);                 // €60.00 off a €600.00 sale — 10 %, but €60 > €50
        $owner = $this->person(Role::OWNER, 'Olga');
        $this->waiver($owner, 6000);                               // €60.00 waived, no sales of her own
        $bruno = $this->person(Role::MANAGER, 'Bruno');
        $this->sale($bruno, 60, original: 100);                    // €0.40 off a €1.00 sale — 40 %, but only €0.40

        $this->assertSame(['Ana' => true, 'Olga' => true], $this->flagged());
        $this->assertSame(2, LossesReport::peopleAboveThreshold($this->sede)['count']);
    }

    public function test_member_discounts_rounding_and_stock_are_not_discounts_a_person_chose(): void
    {
        $carla = $this->person(Role::STAFF, 'Carla');
        $this->sale($carla, 10000, memberDiscount: 9000);           // €90.00 of automatic member discount
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => Genetic::factory()->create(['organisation_id' => $this->org->id])->id,
            'location_id' => $this->sede->id, 'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'cost_per_gram_cents' => 500, 'expires_on' => now()->addYear()]);
        (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, -5000, ['operator_id' => $carla->id, 'reason' => 'Bolsa rota']); // €250 at cost

        $this->assertSame([], $this->flagged());
    }

    public function test_the_amount_is_a_setting(): void
    {
        $bruno = $this->person(Role::MANAGER, 'Bruno');
        $this->sale($bruno, 6000, original: 10000);                // €40.00 off
        $this->assertSame([], $this->flagged(), 'under the €50 default');

        Settings::set('losses_person_discount_alert_cents', 3000, SettingType::CENTS);
        $this->assertSame(['Bruno' => true], $this->flagged());
    }

    public function test_the_alert_says_how_much_and_never_who(): void
    {
        $ana = $this->person(Role::STAFF, 'Ana');
        $this->sale($ana, 54000, original: 60000);

        $alert = collect(CurrentAlerts::for($this->org))->firstWhere('type', AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD);
        $state = new OwnerAlertState(['type' => AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD, 'location_id' => $this->sede->id, 'detail' => $alert['detail']]);
        $state->setRelation('location', $this->sede);
        $text = AlertMessage::text(collect([$state]));

        $this->assertStringContainsString('1 persona ha dado más de '.Money::fromCents(5000)->formatted().' en descuentos esta semana (hasta '.Money::fromCents(6000)->formatted().')', $text);
        $this->assertStringNotContainsString('Ana', $text);
        $this->assertStringNotContainsString('%', $text);
    }
}
