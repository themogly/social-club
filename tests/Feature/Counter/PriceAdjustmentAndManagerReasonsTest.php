<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
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
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 333 — Ben: "When you change the price of a sale it doesn't update the total at the bottom", and "if they're
 * managers, don't require reasons for adjusting a price or for waiving a fee, as a default setting. Instead just put
 * 'manager approved'." One chargeable total for every figure on screen and the commit; `reasons.optional` for OWNER and
 * MANAGER by default, never STAFF, with the person still named on the record.
 */
class PriceAdjustmentAndManagerReasonsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

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
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true]); // €10/g
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN]);
        session(['counter.location_id' => $this->location->id]);
    }

    private function person(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        CounterOperator::set($user);

        return $user;
    }

    private function member(int $feeCents = 0): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents]);

        return $member;
    }

    /** A €20.00 basket (2 g at €10/g), the till open. */
    private function twentyEuroBasket(User $operator): Testable
    {
        if (! TillSession::query()->where('location_id', $this->location->id)->where('status', TillSessionStatus::OPEN->value)->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $operator->id]);
        }

        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '2')->call('addLine');
    }

    private static function button(int $cents): string
    {
        return __('Registrar aportación · :total', ['total' => Money::fromCents($cents)->formatted()]);
    }

    // --- 1. A strain tap brings the pad into view (the scrolling itself: tests/Browser/prove-333-counter.mjs) ------------------

    public function test_choosing_a_strain_asks_the_browser_to_bring_the_pad_into_view_with_no_extra_request(): void
    {
        $pos = $this->twentyEuroBasket($this->person(Role::MANAGER));
        $pos->call('chooseGenetic', $this->genetic->id)->assertDispatched('weight-entry-opened');

        $this->assertMatchesRegularExpression('/data-weight-entry tabindex="-1"\s+x-init="\$nextTick\(\(\) => window\.bringIntoView\(\$el\)\)"/', $pos->html());
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)').matches", $js);
        $this->assertStringContainsString('el.focus({ preventScroll: true })', $js);
    }

    // --- 2. One chargeable total -----------------------------------------------------------------------------------------------

    public function test_an_adjustment_to_15_is_the_total_on_the_button_in_justo_in_falta_and_in_the_commit(): void
    {
        $pos = $this->twentyEuroBasket($this->person(Role::MANAGER))->assertSee(self::button(2000));

        $pos->set('priceOverrideEuros', '15')->set('priceOverrideReason', 'Producto defectuoso')
            ->assertSee(self::button(1500))->assertDontSee(self::button(2000));
        $pos->call('quickCash')->assertSet('cashTendered', '15,00');
        $pos->set('cashTendered', '10')->assertSeeHtml('data-tender-summary')->assertSee(Money::fromCents(500)->formatted()); // Falta 5,00
        $pos->call('quickCash')->call('commitDispensation');

        $d = Dispensation::query()->sole();
        $this->assertSame([1500, 1500, 2000], [$d->total_cents->cents, $d->cash_cents->cents, $d->original_total_cents?->cents ?? (int) $d->getRawOriginal('original_total_cents')]);
    }

    /** Livewire 4: `.blur` before `.live` only syncs in the browser — the field must SEND on blur or Enter. */
    public function test_the_adjustment_field_sends_on_blur_and_enter(): void
    {
        $html = $this->twentyEuroBasket($this->person(Role::MANAGER))->html();

        $this->assertStringContainsString('wire:model.live.blur.enter="priceOverrideEuros"', $html);
        $this->assertStringNotContainsString('wire:model.blur="priceOverrideEuros"', $html);
    }

    public function test_a_combined_visit_adjusted_to_15_with_a_3_euro_bar_line_is_18(): void
    {
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'price_cents' => 300, 'stock' => 10, 'active' => true]);
        $pos = $this->twentyEuroBasket($this->person(Role::MANAGER))->call('addBarItem', $article->id)
            ->set('priceOverrideEuros', '15');

        $pos->assertSee(__('Cobrar visita · :total', ['total' => Money::fromCents(1800)->formatted()]));
        $pos->call('quickCash')->assertSet('cashTendered', '18,00');
    }

    public function test_an_invalid_adjustment_says_so_and_every_total_stays_at_20(): void
    {
        foreach (['abc', '25'] as $typed) {
            $pos = $this->twentyEuroBasket($this->person(Role::MANAGER))->set('priceOverrideEuros', $typed);
            $pos->assertSeeHtml('data-price-override-notice')->assertSee(self::button(2000));
            $pos->call('quickCash')->assertSet('cashTendered', '20,00');
            $pos->set('priceOverrideEuros', '')->assertDontSeeHtml('data-price-override-notice')->assertSee(self::button(2000));
        }
    }

    // --- 3. Managers needn't type a reason ----------------------------------------------------------------------------------------

    public function test_the_permission_is_the_owners_and_managers_by_default_never_staffs(): void
    {
        $this->assertContains(ManagerApproval::PERMISSION, Permissions::for(Role::OWNER));
        $this->assertContains(ManagerApproval::PERMISSION, Permissions::for(Role::MANAGER));
        $this->assertNotContains(ManagerApproval::PERMISSION, Permissions::for(Role::STAFF));
        $this->assertSame(__('Aprobar sin motivo'), Permissions::label(ManagerApproval::PERMISSION));
    }

    public function test_a_manager_adjusts_with_the_reason_left_as_it_is_or_emptied(): void
    {
        foreach ([ManagerApproval::reason(), ''] as $reason) {
            $manager = $this->person(Role::MANAGER);
            $pos = $this->twentyEuroBasket($manager)->assertSeeHtml('<span class="font-normal">'.e(__('(opcional)')).'</span>')->assertSeeHtml('data-reason-optional="true"');
            $pos->set('priceOverrideEuros', '15')->set('priceOverrideReason', $reason)->call('quickCash')->call('commitDispensation');

            $d = Dispensation::query()->latest('dispensed_at')->latest('id')->first();
            $this->assertSame([1500, ManagerApproval::reason(), $manager->id], [$d->total_cents->cents, $d->price_override_reason, $d->price_override_by]);
            $audit = AuditLog::query()->where('action', 'dispensation.price.override')->latest('id')->first();
            $this->assertSame($manager->id, $audit->after['authorised_by'] ?? null);
            $this->assertSame(ManagerApproval::PERMISSION, $audit->after[ManagerApproval::AUDIT_KEY] ?? null);
            Dispensation::query()->delete();
        }
    }

    public function test_a_manager_waives_a_fee_with_the_preselected_option(): void
    {
        $manager = $this->person(Role::MANAGER);
        $member = $this->member(1000);

        $socios = Livewire::test(MembershipCounter::class)->call('selectFeeMember', $member->id)->call('toggleWaive')
            ->assertSet('waiveReason', 'MANAGER_APPROVED')->assertSee(ManagerApproval::reason());
        $socios->call('waiveFee');

        $waiver = MembershipFeePayment::query()->where('method', FeePaymentMethod::WAIVED->value)->sole();
        $this->assertSame([1000, ManagerApproval::reason(), $manager->id], [$waiver->amount_cents->cents, $waiver->reason, $waiver->recorded_by]);
        $audit = AuditLog::query()->where('action', 'membership.fee.waived')->sole();
        $this->assertSame([$manager->id, ManagerApproval::PERMISSION], [$audit->after['operator_id'] ?? null, $audit->after[ManagerApproval::AUDIT_KEY] ?? null]);
    }

    public function test_staff_still_give_a_reason_for_both_and_are_never_offered_the_option(): void
    {
        $this->setRolePermission(Role::STAFF, 'dispensation.price.override', true);
        $staff = $this->person(Role::STAFF);

        $this->twentyEuroBasket($staff)->assertSeeHtml('data-reason-optional="false"')->assertDontSeeHtml('<span class="font-normal">'.e(__('(opcional)')).'</span>')
            ->set('priceOverrideEuros', '15')->call('quickCash')->call('commitDispensation')
            ->assertSet('flashMessage', __('Indica el motivo del ajuste de precio (queda registrado).'));
        $this->assertSame(0, Dispensation::query()->count());

        $member = $this->member(1000);
        $socios = Livewire::test(MembershipCounter::class)->call('selectFeeMember', $member->id)->call('toggleWaive')
            ->assertSet('waiveReason', '')->assertDontSee(ManagerApproval::reason());
        $socios->set('waiveReason', 'MANAGER_APPROVED')->call('waiveFee'); // a crafted value
        $this->assertSame(0, MembershipFeePayment::query()->count());
    }

    public function test_taking_the_permission_from_managers_on_the_roles_page_makes_the_reason_required_again(): void
    {
        $this->setRolePermission(Role::MANAGER, ManagerApproval::PERMISSION, false);
        $manager = $this->person(Role::MANAGER);
        CounterOperator::set($manager->fresh());

        $this->twentyEuroBasket($manager)->set('priceOverrideEuros', '15')->call('quickCash')->call('commitDispensation')
            ->assertSet('flashMessage', __('Indica el motivo del ajuste de precio (queda registrado).'));
        $this->assertSame(0, Dispensation::query()->count());

        Livewire::test(MembershipCounter::class)->call('selectFeeMember', $this->member(1000)->id)->call('toggleWaive')
            ->assertSet('waiveReason', '')->assertDontSee(ManagerApproval::reason());
    }
}
