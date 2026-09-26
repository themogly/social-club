<?php

namespace Tests\Feature\Wallet;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Bar\CommitOrder;
use App\Actions\Counter\CommitCombinedSettle;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Members\SetMemberDebtLimit;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 259 — a member tab: owner-approved only, reminded on sight, and added to by one deliberate button.
 *
 * Before: `CommitDispensation`, `CommitOrder` and so `CommitCombinedSettle` recorded their wallet spend with
 * `allow_debt => true`, skipping the debt check entirely. With debt switched OFF club-wide, a dispensation paid
 * from an empty wallet committed and left the member owing. Now the member's owner-set `debt_limit_cents` is the
 * grant, measured against their TOTAL debt across every sede, and a sale reaches the tab only through
 * "Añadir a la cuenta".
 *
 * Prices are €1.00/g here so a tab of exactly €2.00 is 2 g and €2.01 is 2.01 g.
 */
class MemberTabTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private Genetic $genetic;

    private Member $member;

    private User $owner;

    private User $manager;

    private User $staff;

    /** @var array<string, TillSession> */
    private array $tills = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        session(['counter.location_id' => $this->centro->id]);

        $this->owner = $this->user(Role::OWNER);
        $this->manager = $this->user(Role::MANAGER);
        $this->staff = $this->user(Role::STAFF);
        $this->actingAs($this->staff);
        CounterOperator::set($this->staff);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 1000000, 'monthly_limit_cg' => 1000000,
        ]);

        foreach ([$this->centro, $this->norte] as $sede) {
            GeneticPrice::create([
                'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $sede->id,
                'tier_id' => null, 'price_per_gram_cents' => 100, 'active' => true,
            ]);
            Batch::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $sede->id,
                'remaining_cg' => 1000000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
            ]);
            Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $sede->id,
                'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
                'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
                'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
            ]);
            $this->tills[$sede->id] = (new OpenTill)->handle($sede, 'POS-1', 10000);
            $this->debtAllowed(true, $sede);
        }
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->centro->id, $this->norte->id]);

        return $user;
    }

    private function debtAllowed(bool $on, Location $sede): void
    {
        Settings::set('wallet_debt_allowed', $on, SettingType::BOOL, $sede->id);
    }

    private function approve(?int $limitCents): void
    {
        (new SetMemberDebtLimit)->handle($this->member, $this->owner, $limitCents, 'Cliente de confianza');
        $this->member->refresh();
    }

    /** A dispensation of `$cg` centigrams (= that many cents at €1/g) paid wholly from the wallet. */
    private function dispense(int $cg, ?Location $sede = null, bool $onTab = false): Dispensation
    {
        $sede ??= $this->centro;

        return (new CommitDispensation)->handle($this->member, $sede,
            [['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => $cg]],
            ['operator_id' => $this->staff->id, 'till_session_id' => $this->tills[$sede->id]->id,
                'cash_cents' => 0, 'wallet_cents' => $cg, 'on_tab' => $onTab]);
    }

    /** Owe `$cents` at a sede, through the wallet writer (an approved debit). */
    private function owe(int $cents, Location $sede): void
    {
        (new RecordWalletTransaction)->handle($this->member, $sede, -$cents, WalletTransactionType::ADJUSTMENT, ['reason' => 'fixture']);
    }

    private function refused(callable $act, string $why): void
    {
        try {
            $act();
            $this->fail($why);
        } catch (DebtLimitExceededException) {
            $this->addToAssertionCount(1);
        }
    }

    // --- 1. The hole, closed -----------------------------------------------------------------------------------

    public function test_with_debt_off_and_no_approval_an_empty_wallet_dispensation_is_refused(): void
    {
        $this->debtAllowed(false, $this->centro);

        $this->refused(fn () => $this->dispense(723), 'A tab opened with debt switched off and no approval.');

        $this->assertSame(0, Wallet::balance($this->member->id, $this->centro->id));
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_even_an_approved_member_is_never_put_on_the_tab_silently(): void
    {
        $this->approve(2000);

        $this->refused(fn () => $this->dispense(723), 'An empty-wallet payment opened a tab without "Añadir a la cuenta".');
        $this->assertSame(0, Wallet::balance($this->member->id, $this->centro->id));
    }

    // --- 2. Approved, within the limit --------------------------------------------------------------------------

    public function test_an_approved_member_goes_on_the_tab_through_the_deliberate_choice(): void
    {
        $this->approve(2000);

        $dispensation = $this->dispense(723, onTab: true);

        $this->assertSame(-723, Wallet::balance($this->member->id, $this->centro->id));
        $audit = AuditLog::query()->where('action', 'wallet.tab.added')->sole();
        $this->assertSame(723, $audit->after['added_cents']);
        $this->assertSame($dispensation->id, $audit->after['sale_id']);
        $this->assertSame($this->staff->id, $audit->after['operator_id']);
    }

    // --- 3. The never-exceed rule --------------------------------------------------------------------------------

    public function test_the_running_total_never_passes_the_approved_limit(): void
    {
        $this->approve(2000);
        $this->owe(1800, $this->centro);

        $this->refused(fn () => $this->dispense(500, onTab: true), 'A €5 tab took a member owing €18 past €20.');
        $this->refused(fn () => $this->dispense(201, onTab: true), '€2.01 took the total past €20.');

        $this->dispense(200, onTab: true);
        $this->assertSame(-2000, Wallet::balance($this->member->id, $this->centro->id));
        $this->refused(fn () => $this->dispense(1, onTab: true), 'One cent past the limit landed.');
    }

    // --- 3b. The limit is global, not per sede --------------------------------------------------------------------

    public function test_a_second_sede_cannot_run_a_second_tab(): void
    {
        $this->approve(2000);
        $this->owe(1800, $this->centro);

        $this->refused(fn () => $this->dispense(500, $this->norte, onTab: true), 'Norte opened a fresh €20 tab beside Centro\'s €18.');

        $this->dispense(200, $this->norte, onTab: true);
        $this->assertSame(0 - 200, Wallet::balance($this->member->id, $this->norte->id));
        $this->assertSame(2000, Wallet::totalDebtCents($this->member->id));
    }

    // --- 3a. A lowered limit claws nothing back --------------------------------------------------------------------

    public function test_a_limit_lowered_below_existing_debt_only_stops_new_debt(): void
    {
        $this->approve(2000);
        $this->owe(1800, $this->centro);

        $this->approve(1000); // no exception, no clawback
        $this->assertSame(-1800, Wallet::balance($this->member->id, $this->centro->id));
        $this->refused(fn () => $this->dispense(1, onTab: true), 'A member over their lowered limit added more.');

        // A payment into a negative wallet always posts, even while over the limit.
        (new RecordWalletTransaction)->handle($this->member, $this->centro, 900, WalletTransactionType::TOPUP, []);
        $this->assertSame(-900, Wallet::balance($this->member->id, $this->centro->id));

        $this->dispense(100, onTab: true);
        $this->assertSame(-1000, Wallet::balance($this->member->id, $this->centro->id));
        $this->refused(fn () => $this->dispense(1, onTab: true), 'Past the lowered limit.');
    }

    public function test_debt_switched_off_at_the_sede_overrides_any_member_grant(): void
    {
        $this->approve(5000);
        $this->debtAllowed(false, $this->centro);

        $this->refused(fn () => $this->dispense(100, onTab: true), 'The sede master switch was ignored.');
    }

    public function test_a_club_wide_cap_binds_when_it_is_tighter(): void
    {
        $this->approve(5000);
        Settings::set('wallet_debt_limit_cents', 1000, SettingType::INT, $this->centro->id);

        $this->refused(fn () => $this->dispense(1001, onTab: true), 'The club cap (tighter) was ignored.');
        $this->dispense(1000, onTab: true);
        $this->assertSame(-1000, Wallet::balance($this->member->id, $this->centro->id));
    }

    // --- 4. Bar + combined settle ---------------------------------------------------------------------------------

    public function test_the_bar_and_the_combined_settle_honour_the_same_gate(): void
    {
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->centro->id,
            'price_cents' => 300, 'stock' => 10, 'active' => true,
        ]);
        $orderOptions = ['member_id' => $this->member->id, 'till_session_id' => $this->tills[$this->centro->id]->id,
            'operator_id' => $this->staff->id, 'cash_cents' => 0, 'wallet_cents' => 300];

        $this->refused(fn () => (new CommitOrder)->handle($this->centro, [['article_id' => $article->id, 'qty' => 1]], $orderOptions),
            'The bar walked an unapproved member into debt.');

        $settle = fn (bool $onTab) => (new CommitCombinedSettle)->handle($this->member, $this->centro,
            [['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 200]],
            [['article_id' => $article->id, 'qty' => 1]],
            ['till_session_id' => $this->tills[$this->centro->id]->id, 'operator_id' => $this->staff->id, 'on_tab' => $onTab,
                'dispensation' => ['cash_cents' => 0, 'wallet_cents' => 200], 'order' => ['cash_cents' => 0, 'wallet_cents' => 300]]);

        $this->refused(fn () => $settle(true), 'The combined settle walked an unapproved member into debt.');

        $this->approve(400);
        $this->refused(fn () => $settle(true), 'The combined draw (€5) passed a €4 tab.');
        $this->approve(500);
        $settle(true);
        $this->assertSame(-500, Wallet::balance($this->member->id, $this->centro->id));
    }

    // --- 5. Who may approve, per sede -----------------------------------------------------------------------------

    public function test_the_owner_always_may_a_manager_only_where_the_sede_allows_staff_never(): void
    {
        (new SetMemberDebtLimit)->handle($this->member, $this->owner, 2000, 'ok');
        $this->assertSame(2000, $this->member->fresh()->debt_limit_cents);

        // Manager at Centro, toggle OFF there → refused.
        app(ActiveScope::class)->setLocation($this->centro->id);
        try {
            (new SetMemberDebtLimit)->handle($this->member, $this->manager, 3000, 'x');
            $this->fail('A manager approved a tab where the sede does not allow it.');
        } catch (AuthorizationException) {
            $this->assertSame(2000, $this->member->fresh()->debt_limit_cents);
        }

        // The owner turns it on at Norte → the same manager, working at Norte, may.
        Settings::set('managers_can_approve_debt', true, SettingType::BOOL, $this->norte->id);
        app(ActiveScope::class)->setLocation($this->norte->id);
        (new SetMemberDebtLimit)->handle($this->member, $this->manager, 3000, 'ok');
        $this->assertSame(3000, $this->member->fresh()->debt_limit_cents);

        // With no sede chosen ("all locations") a manager cannot.
        app(ActiveScope::class)->setLocation(null);
        $this->assertFalse($this->manager->can('approveDebt', $this->member));

        // Staff never, even at a sede that allows managers.
        app(ActiveScope::class)->setLocation($this->norte->id);
        $this->assertFalse($this->staff->can('approveDebt', $this->member));
    }

    public function test_only_the_owner_can_flip_the_managers_approval_toggle(): void
    {
        Filament::setCurrentPanel('admin');
        $edit = fn (User $who, bool $value) => Livewire::actingAs($who)
            ->test(EditLocation::class, ['record' => $this->centro->getRouteKey()])
            ->fillForm(['managers_can_approve_debt' => $value])
            ->call('save');

        // A manager (who may edit the sede) cannot grant themselves the power — the value is left as it was.
        $this->assertTrue($this->manager->can('update', $this->centro), 'precondition: managers edit their sede');
        $edit($this->manager, true);
        $this->assertFalse((bool) Settings::get('managers_can_approve_debt', false, $this->centro->id));

        $edit($this->owner, true);
        $this->assertTrue((bool) Settings::get('managers_can_approve_debt', false, $this->centro->id));
    }

    public function test_the_limit_is_never_mass_assignable(): void
    {
        $member = Member::create([
            'organisation_id' => $this->org->id, 'member_no' => 'M-99999', 'first_name' => 'A', 'last_name' => 'B', 'debt_limit_cents' => 99999,
        ]);

        $this->assertNull($member->fresh()->debt_limit_cents, 'a form/import/sign-up payload set a tab');
    }

    // --- 6. The reminder ---------------------------------------------------------------------------------------------

    public function test_the_reminder_fires_for_any_member_who_owes_whatever_the_toggles(): void
    {
        // Put an UNAPPROVED member in debt the one way that can still happen (a reversal that must post).
        (new RecordWalletTransaction)->handle($this->member, $this->centro, -500, WalletTransactionType::ADJUSTMENT, ['allow_debt' => true]);
        Settings::set('wallet_door_debt_threshold_cents', 100000, SettingType::INT, $this->centro->id);

        foreach ([false, true] as $restrict) {
            Settings::set('restrict_pos_to_checked_in', $restrict, SettingType::BOOL, $this->centro->id);
            if ($restrict) {
                (new CheckInMember)->handle($this->member, $this->centro, ['operator_id' => $this->staff->id]);
            }

            Livewire::test(DispensaryPos::class)
                ->call('selectMember', $this->member->id)
                ->assertSeeHtml('data-member-owes')
                ->assertSee(__('Este socio debe :money', ['money' => Money::fromCents(500)->formatted()]))
                ->assertSeeHtml('data-collect-debt');
        }
    }

    public function test_collecting_the_debt_is_a_cash_top_up_into_the_open_till(): void
    {
        (new RecordWalletTransaction)->handle($this->member, $this->centro, -500, WalletTransactionType::ADJUSTMENT, ['allow_debt' => true]);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('collectDebt')
            ->assertSet('flashType', 'success');

        $this->assertSame(0, Wallet::balance($this->member->id, $this->centro->id));
        $this->assertSame(1, $this->member->walletTransactions()->withoutGlobalScopes()
            ->where('type', WalletTransactionType::TOPUP->value)->where('till_session_id', $this->tills[$this->centro->id]->id)->count());
    }

    // --- 7. The button ---------------------------------------------------------------------------------------------

    public function test_the_add_to_tab_button_exists_only_for_an_approved_member_with_headroom(): void
    {
        $screen = fn () => Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '5')
            ->call('addLine');

        $screen()->assertDontSeeHtml('data-add-to-tab'); // unapproved: absent

        $this->approve(2000);
        $screen()->assertSeeHtml('data-add-to-tab'); // approved, €5 fits

        $this->approve(300);
        $html = $screen()->html();
        $this->assertMatchesRegularExpression('/data-add-to-tab[^>]*disabled|disabled[^>]*data-add-to-tab/', $html, '€5 past a €3 tab must be disabled');

        $this->approve(2000);
        $this->owe(2000, $this->centro);
        $screen()->assertDontSeeHtml('data-add-to-tab'); // no headroom left: absent
    }

    public function test_the_button_puts_the_unpaid_part_on_the_tab(): void
    {
        $this->approve(2000);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '5')
            ->call('addLine')
            ->set('cashTendered', '2')      // pays €2 today…
            ->call('commitOnTab')           // …the other €3 on the tab
            ->assertSet('flashType', 'success');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame(200, $dispensation->cash_cents->cents);
        $this->assertSame(300, $dispensation->wallet_cents->cents);
        $this->assertSame(-300, Wallet::balance($this->member->id, $this->centro->id));
    }

    public function test_an_unapproved_member_cannot_be_put_on_the_tab_by_a_forged_call(): void
    {
        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '5')
            ->call('addLine')
            ->set('onTab', true)
            ->set('walletInput', '5')
            ->call('commitDispensation')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Wallet::balance($this->member->id, $this->centro->id));
    }

    // --- 8. Credits still post ---------------------------------------------------------------------------------------

    public function test_a_void_still_credits_a_member_in_debt(): void
    {
        $this->approve(2000);
        $dispensation = $this->dispense(723, onTab: true);
        $this->approve(null); // even with the tab withdrawn, the reversal must post

        $manager = $this->manager;
        (new VoidDispensation)->handle($dispensation, $manager, 'Error');

        $this->assertSame(0, Wallet::balance($this->member->id, $this->centro->id));
    }
}
