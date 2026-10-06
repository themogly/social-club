<?php

namespace Tests\Feature\Counter;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Members\SetMemberDebtLimit;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 356 — the club: "Some stock is added too cheap — they need to be able to make it more", and "Can we just get rid
 * of the reason field if it's set to optional." The counter's price adjustment goes up as well as down; for a holder of
 * `reasons.optional` the reason box is not shown at all and the WRITER fills in «Aprobado por responsable».
 */
class TwoWayPriceAndHiddenReasonsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $this->batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]); // €10/g
        session(['counter.location_id' => $this->location->id]);
    }

    private function person(Role $role, array $extra = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        if ($extra !== []) {
            $user->givePermissionTo($extra);
        }
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        CounterOperator::set($user);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    /** A €30.00 basket (3 g at €10/g), the till open. */
    private function thirtyEuroBasket(User $operator, ?Member $member = null): Testable
    {
        if (! TillSession::query()->where('location_id', $this->location->id)->where('status', TillSessionStatus::OPEN->value)->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $operator->id]);
        }

        return Livewire::test(DispensaryPos::class)->call('selectMember', ($member ?? $this->member())->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '3')->call('addLine');
    }

    private static function button(int $cents): string
    {
        return __('Registrar aportación · :total', ['total' => Money::fromCents($cents)->formatted()]);
    }

    // --- 1–3. Two ways, and the notice says which ------------------------------------------------------------------------------

    public function test_a_manager_raises_30_to_34_and_it_is_charged_everywhere(): void
    {
        $pos = $this->thirtyEuroBasket($this->person(Role::MANAGER))->set('priceOverrideEuros', '34')
            ->assertSee(self::button(3400))
            ->assertSee(__(':amount sobre el precio calculado', ['amount' => '+'.Money::fromCents(400)->formatted()]));

        $pos->call('quickCash');
        $this->assertSame('34.00', $pos->get('cashTendered'));
        $pos->set('cashTendered', '40')->assertSee(Money::fromCents(600)->formatted()); // change
        $pos->call('commitDispensation')->assertSet('flashType', 'success');

        $d = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame([3400, 3000], [$d->total_cents->cents, $d->original_total_cents->cents]);
        $this->get(route('counter.pos.receipt', $d))->assertOk()->assertSee(Money::fromCents(3400)->formatted())->assertSee(Money::fromCents(3000)->formatted());
    }

    public function test_lowering_and_zero_still_work_and_the_notice_says_minus(): void
    {
        $manager = $this->person(Role::MANAGER);
        $this->thirtyEuroBasket($manager)->set('priceOverrideEuros', '25')
            ->assertSee(__(':amount sobre el precio calculado', ['amount' => '−'.Money::fromCents(500)->formatted()]))
            ->call('quickCash')->call('commitDispensation');
        $this->thirtyEuroBasket($manager)->set('priceOverrideEuros', '0')->call('commitDispensation');

        $this->assertSame([2500, 0], Dispensation::query()->withoutGlobalScopes()->orderBy('created_at')->get()->map(fn (Dispensation $d): int => $d->total_cents->cents)->all());
    }

    // --- 4. Downstream ------------------------------------------------------------------------------------------------------------

    public function test_a_raised_total_settles_with_the_wallet_on_the_tab_in_a_combined_visit_and_caps_the_refund(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $manager = $this->person(Role::MANAGER);

        // The wallet: €10 of credit, €34 owed → €24 cash.
        $walletMember = $this->member();
        (new RecordWalletTransaction)->handle($walletMember, $this->location, 1000, WalletTransactionType::TOPUP, []);
        $this->thirtyEuroBasket($manager, $walletMember)->set('priceOverrideEuros', '34')->set('walletInput', '10')
            ->call('quickCash')->assertSet('cashTendered', '24.00')->call('commitDispensation')->assertSet('flashType', 'success');
        $this->assertSame(0, Wallet::balance($walletMember->id, $this->location->id));

        // The tab (259): €34 owed, €4 handed → €30 on the tab.
        $tabMember = $this->member();
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL, (string) $this->location->id);
        (new SetMemberDebtLimit)->handle($tabMember, $owner, 10000, 'Aprobado');
        $this->thirtyEuroBasket($manager, $tabMember)->set('priceOverrideEuros', '34')->set('cashTendered', '4')
            ->call('commitOnTab')->assertSet('flashType', 'success');
        $this->assertSame(-3000, Wallet::balance($tabMember->id, $this->location->id));

        // A combined visit (263): €34 raised + a €3 drink = €37.
        $drink = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'price_cents' => 300, 'stock' => 10]);
        $this->thirtyEuroBasket($manager)->set('priceOverrideEuros', '34')->call('addBarItem', $drink->id)
            ->call('quickCash')->assertSet('cashTendered', '37.00')->call('commitDispensation')->assertSet('flashType', 'success');

        // The refund cap is what was charged: €34, not €30.
        $raised = Dispensation::query()->withoutGlobalScopes()->where('member_id', $walletMember->id)->sole();
        (new RefundDispensation)->handle($raised, $owner, ['amount_cents' => 3400, 'reason' => 'Prueba', 'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET]);
        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($raised->fresh(), $owner, ['amount_cents' => 1, 'reason' => 'Prueba', 'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET]);
    }

    // --- 5. The lasting fix --------------------------------------------------------------------------------------------------------

    public function test_the_batch_price_link_shows_after_a_raise_for_a_prices_manage_holder_only(): void
    {
        $this->thirtyEuroBasket($this->person(Role::MANAGER))->set('priceOverrideEuros', '34')
            ->assertSeeHtml('data-batch-price-link')->assertSee(__('¿El lote está mal de precio? Cambiar el precio del lote'))
            ->set('priceOverrideEuros', '25')->assertDontSeeHtml('data-batch-price-link');

        $this->thirtyEuroBasket($this->person(Role::STAFF, ['dispensation.price.override']))->set('priceOverrideEuros', '34')
            ->assertDontSeeHtml('data-batch-price-link');
    }

    // --- 6–10. An optional reason is not shown; the writer fills it in -------------------------------------------------------

    public function test_a_manager_sees_no_reason_box_and_the_commit_records_aprobado_por_responsable(): void
    {
        $manager = $this->person(Role::MANAGER);
        $this->thirtyEuroBasket($manager)->assertDontSeeHtml('id="price-override-reason"')
            ->set('priceOverrideEuros', '25')->call('quickCash')->call('commitDispensation')->assertSet('flashType', 'success');

        $d = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame([ManagerApproval::reason(), $manager->id], [$d->price_override_reason, $d->price_override_by]);
        $audit = AuditLog::query()->where('action', 'dispensation.price.override')->sole();
        $this->assertSame(ManagerApproval::PERMISSION, $audit->after[ManagerApproval::AUDIT_KEY] ?? null);
    }

    public function test_a_manager_waives_a_fee_in_one_tap_with_no_picker(): void
    {
        $manager = $this->person(Role::MANAGER);
        // A THERAPEUTIC member: the record-backed reason a non-holder would see pre-selected must not slip in unseen.
        $member = $this->member();
        $member->update(['is_therapeutic' => true]);
        Membership::query()->where('member_id', $member->id)->update(['fee_cents' => 1000]);
        (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $manager->id]);

        Livewire::test(MembershipCounter::class)->call('selectFeeMember', $member->id)->call('toggleWaive')
            ->assertDontSeeHtml('data-waive-reason=')->assertSeeHtml('data-fee-waive-submit')
            ->call('waiveFee');

        $waiver = MembershipFeePayment::query()->where('method', FeePaymentMethod::WAIVED->value)->sole();
        $this->assertSame([ManagerApproval::reason(), $manager->id], [$waiver->reason, $waiver->recorded_by]);
        $this->assertSame(ManagerApproval::PERMISSION, AuditLog::query()->where('action', 'membership.fee.waived')->sole()->after[ManagerApproval::AUDIT_KEY] ?? null);
    }

    public function test_the_writers_decide_an_empty_reason_from_staff_is_refused_and_from_a_manager_filled_in(): void
    {
        $staff = $this->person(Role::STAFF, ['dispensation.price.override', 'membership.fee.waive']);
        $manager = $this->person(Role::MANAGER);
        $line = [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 300]];

        try {
            (new CommitDispensation)->handle($this->member(), $this->location, $line, ['operator_id' => $staff->id, 'price_override_cents' => 2500, 'price_override_by' => $staff, 'price_override_reason' => '']);
            $this->fail('an empty reason from staff was accepted');
        } catch (RuntimeException) {
        }
        $d = (new CommitDispensation)->handle($this->member(), $this->location, $line, ['operator_id' => $manager->id, 'price_override_cents' => 2500, 'price_override_by' => $manager, 'price_override_reason' => '']);
        $this->assertSame(ManagerApproval::reason(), $d->price_override_reason);

        $owing = $this->member();
        $membership = Membership::query()->where('member_id', $owing->id)->sole();
        $membership->update(['fee_cents' => 1000]);
        try {
            (new RecordFeePayment)->handle($membership->fresh(), 1000, FeePaymentMethod::WAIVED, ['operator_id' => $staff->id, 'reason' => '']);
            $this->fail('an empty waiver reason from staff was accepted');
        } catch (\InvalidArgumentException) {
        }
        $waiver = (new RecordFeePayment)->handle($membership->fresh(), 1000, FeePaymentMethod::WAIVED, ['operator_id' => $manager->id, 'reason' => null]);
        $this->assertSame(ManagerApproval::reason(), $waiver->reason);
    }

    public function test_staff_with_the_price_permission_still_see_a_required_reason_box(): void
    {
        $this->thirtyEuroBasket($this->person(Role::STAFF, ['dispensation.price.override']))
            ->assertSeeHtml('id="price-override-reason"')
            ->set('priceOverrideEuros', '25')->call('quickCash')->call('commitDispensation')
            ->assertSet('flashType', 'error');
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_box_follows_the_person_at_the_pin(): void
    {
        $staff = $this->person(Role::STAFF, ['dispensation.price.override']);
        $pos = $this->thirtyEuroBasket($staff)->assertSeeHtml('id="price-override-reason"');

        $manager = $this->person(Role::MANAGER);
        $pos->call('$refresh')->assertDontSeeHtml('id="price-override-reason"');
        CounterOperator::set($staff);
        $this->actingAs($staff);
        $pos->call('$refresh')->assertSeeHtml('id="price-override-reason"');
        $this->assertNotNull($manager);
    }

    public function test_taking_reasons_optional_from_managers_brings_the_box_back(): void
    {
        $this->setRolePermission(Role::MANAGER, ManagerApproval::PERMISSION, false);
        $this->thirtyEuroBasket($this->person(Role::MANAGER))->assertSeeHtml('id="price-override-reason"');
    }
}
