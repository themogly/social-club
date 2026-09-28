<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\SetMemberDebtLimit;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\TillSummary;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 293 — behaviour pins, written BEFORE the counter stopped re-rendering the whole screen, green before and
 * after. That prompt changes how much is re-rendered, never what is decided: these figures are the proof.
 *
 * One combined visit (263) adds, removes, pays part from the wallet and part in cash, and commits; a second puts the
 * unpaid part on the tab (259); a permission revoked mid-shift is still refused (266); a basket survives a lock (198).
 */
class RoundTripPinsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $operator;

    private Genetic $genetic;

    private Batch $batch;

    private Article $beer;

    private Member $member;

    private TillSession $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole(Role::STAFF->value);
        $this->operator->locations()->sync([$this->location->id]);
        $this->actingAs($this->operator);
        CounterOperator::set($this->operator);
        $this->till = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);
        $this->beer = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Cerveza', 'price_cents' => 150, 'stock' => 20, 'active' => true,
        ]);
        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);
    }

    public function test_one_combined_visit_gives_the_same_totals_stock_and_till_entries(): void
    {
        (new RecordWalletTransaction)->handle($this->member, $this->location, 500, WalletTransactionType::TOPUP);

        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)->call('addLine', '2', 'grams')   // €20,00
            ->call('chooseGenetic', $this->genetic->id)->call('addLine', '1', 'grams')   // €10,00…
            ->call('removeLine', 1)                                                        // …removed
            ->call('addBarItem', $this->beer->id)->call('addBarItem', $this->beer->id);   // €3,00

        $pos->set('walletInput', '5,00')->set('cashTendered', '20,00')->call('commitDispensation')->assertSet('flashType', 'success');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->sole();
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame(2000, $dispensation->total_cents->cents);
        $this->assertSame(300, $order->total_cents->cents);
        $this->assertSame(500, $dispensation->wallet_cents->cents + $order->wallet_cents->cents, 'the wallet paid €5 of the visit');
        $this->assertSame(1800, $dispensation->cash_cents->cents + $order->cash_cents->cents, 'cash paid the other €18');

        $this->assertSame(49800, (int) $this->batch->fresh()->remaining_cg->centigrams);
        $this->assertSame(18, (int) $this->beer->fresh()->stock);
        $this->assertSame(2, StockMovement::query()->withoutGlobalScopes()->count(), 'one movement per stock item');
        $this->assertSame(0, Wallet::balance($this->member->id, $this->location->id));
        $this->assertSame(10000 + 1800, TillSummary::expectedCents($this->till->fresh()));
        $this->assertSame([], $pos->get('basket'));
        $this->assertSame([], $pos->get('barBasket'));
    }

    public function test_the_unpaid_part_of_a_visit_goes_on_the_tab(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL, $this->location->id);
        (new SetMemberDebtLimit)->handle($this->member, $owner, 2000, 'Socio de confianza');

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)->call('addLine', '1', 'grams')   // €10,00
            ->set('cashTendered', '4')
            ->call('commitOnTab')
            ->assertSet('flashType', 'success');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame(400, $dispensation->cash_cents->cents);
        $this->assertSame(600, $dispensation->wallet_cents->cents);
        $this->assertSame(-600, Wallet::balance($this->member->id, $this->location->id));
        $this->assertSame(10000 + 400, TillSummary::expectedCents($this->till->fresh()));
    }

    public function test_a_permission_revoked_mid_shift_still_refuses_the_action(): void
    {
        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('addBarItem', $this->beer->id);
        $this->assertCount(1, $pos->get('barBasket'));

        $this->setRolePermission(Role::STAFF, 'pos.bar', false);

        $pos->call('addBarItem', $this->beer->id)->assertSet('flashType', 'error');
        $this->assertCount(1, $pos->get('barBasket'), 'a second line was added after the permission went');

        $pos->call('commitDispensation')->assertSet('flashType', 'error');
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(20, (int) $this->beer->fresh()->stock);

        Livewire::test(BarPos::class)->assertForbidden(); // the standalone Bar is closed to this device now
    }

    public function test_the_basket_survives_a_lock(): void
    {
        $this->operator->update(['pin' => '48151623']);

        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)->call('addLine', '2', 'grams')
            ->call('addBarItem', $this->beer->id);
        $basket = $pos->get('basket');
        $barBasket = $pos->get('barBasket');

        $pos->call('lockCounter')
            ->set('operatorPin', '48151623')
            ->call('unlockOperator')
            ->assertSet('basket', $basket)
            ->assertSet('barBasket', $barBasket)
            ->assertSet('memberId', $this->member->id);
    }
}
