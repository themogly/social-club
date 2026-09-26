<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use App\Livewire\Counter\WhosInside;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockTake;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 255 — the person at the counter is the PIN operator, not the tablet's login.
 *
 * The install this models is the runbook's: the tablet logged in once as the OWNER, staff and managers working
 * it by PIN. Before the fix every counter permission check asked `Auth::user()` — the owner — so a STAFF PIN
 * closed the till (`till.close` is manager+), four writes ran with nobody identified at all, and the `*_by`
 * columns named the owner for a manager's decisions. The register an inspection reads attributed a person's acts
 * to the device.
 */
class CounterOperatorIsTheActorTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private User $ownerTablet;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        $this->ownerTablet = $this->user(Role::OWNER);
        $this->staff = $this->user(Role::STAFF);
        $this->manager = $this->user(Role::MANAGER);

        $this->actingAs($this->ownerTablet); // one device login, set once
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function till(): TillSessionModel
    {
        return TillSessionModel::query()->withoutGlobalScopes()->sole();
    }

    private function member(bool $eligible = true): Member
    {
        $member = Member::factory()->create([
            // Suspended ⇒ blocked at the door (the sanction rule), which is what an override is for.
            'organisation_id' => $this->org->id, 'status' => $eligible ? MemberStatus::ACTIVE : MemberStatus::SUSPENDED,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);

        return $member;
    }

    private function sellable(): Batch
    {
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true,
        ]);

        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    // --- 1. A staff PIN inherits nothing from the tablet's login ----------------------------------------------

    public function test_a_staff_pin_cannot_close_the_till_on_an_owner_tablet(): void
    {
        CounterOperator::set($this->staff);

        Livewire::test(TillSession::class)
            ->call('startClose')
            ->set('countInput', '100')
            ->call('submitCount')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', __('No tienes permiso para cerrar la caja.'));

        $this->assertSame(TillSessionStatus::OPEN, $this->till()->status);
    }

    public function test_a_staff_pin_cannot_authorise_a_door_override_on_an_owner_tablet(): void
    {
        $member = $this->member(eligible: false);
        CounterOperator::set($this->staff);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->set('overrideReason', 'Conocido')
            ->call('confirmOverride')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, CheckIn::query()->withoutGlobalScopes()->count());
    }

    // --- 4. Positive parity: it restricts by operator, it does not forbid the act ------------------------------

    public function test_a_manager_pin_closes_the_till_on_the_same_tablet_and_is_the_recorded_closer(): void
    {
        CounterOperator::set($this->manager);

        Livewire::test(TillSession::class)
            ->call('startClose')
            ->set('countInput', '100')
            ->call('submitCount')
            ->assertSet('countSubmitted', true);

        $till = $this->till();
        $this->assertSame(TillSessionStatus::CLOSED, $till->status);
        $this->assertSame($this->manager->id, $till->closed_by, 'The Z-report must name the manager, not the tablet.');
    }

    // --- 2. No operator ⇒ no write -----------------------------------------------------------------------------

    public function test_the_till_close_refuses_with_nobody_identified(): void
    {
        Livewire::test(TillSession::class)
            ->call('startClose')
            ->set('countInput', '100')
            ->call('submitCount')
            ->assertSet('operatorPanelOpen', true);

        $this->assertSame(TillSessionStatus::OPEN, $this->till()->status);
    }

    public function test_the_stock_take_refuses_with_nobody_identified(): void
    {
        Livewire::test(TillSession::class)
            ->call('submitReweigh')
            ->assertSet('operatorPanelOpen', true);

        $this->assertSame(0, StockTake::query()->withoutGlobalScopes()->count());
    }

    public function test_the_door_check_out_refuses_with_nobody_identified(): void
    {
        $member = $this->member();
        (new CheckInMember)->handle($member, $this->location, ['operator_id' => $this->staff->id]);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->call('checkOut')
            ->assertSet('operatorPanelOpen', true);

        $this->assertNull(CheckIn::query()->withoutGlobalScopes()->sole()->checked_out_at, 'The member was checked out by nobody.');
    }

    public function test_the_receipt_email_refuses_with_nobody_identified(): void
    {
        Mail::fake();
        $batch = $this->sellable();
        $dispensation = (new CommitDispensation)->handle($this->member(), $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 100]],
            ['operator_id' => $this->staff->id]);

        Livewire::test(DispensaryPos::class)
            ->set('lastDispensationId', $dispensation->id)
            ->call('emailReceipt')
            ->assertSet('operatorPanelOpen', true);

        Mail::assertNothingQueued();
    }

    public function test_the_receipt_email_cannot_reach_another_sedes_dispensation(): void
    {
        Mail::fake();
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $other->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $other->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $member = $this->member();
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $other->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);
        $foreign = (new CommitDispensation)->handle($member, $other,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 100]],
            ['operator_id' => $this->staff->id]);

        CounterOperator::set($this->staff);
        Livewire::test(DispensaryPos::class)
            ->set('lastDispensationId', $foreign->id)
            ->call('emailReceipt');

        Mail::assertNothingQueued();
    }

    // --- 3. The recorded actor is the operator ------------------------------------------------------------------

    public function test_the_door_override_is_authorised_by_the_operator_not_the_tablet(): void
    {
        $member = $this->member(eligible: false);
        CounterOperator::set($this->manager);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->set('overrideReason', 'Conocido del club')
            ->call('confirmOverride')
            ->assertSet('flashType', 'success');

        $audit = AuditLog::query()->where('action', 'checkin.override')->sole();
        $this->assertSame($this->manager->id, $audit->after['authorised_by']);
    }

    public function test_the_price_override_is_by_the_operator_not_the_tablet(): void
    {
        $this->sellable();
        $member = $this->member();
        CounterOperator::set($this->manager);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '1')
            ->call('addLine')
            ->set('priceOverrideEuros', '6')
            ->set('priceOverrideReason', 'Producto mohoso')
            ->call('commitDispensation');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame(600, $dispensation->total_cents->cents);
        $this->assertSame($this->manager->id, $dispensation->price_override_by);
    }

    public function test_the_stock_take_is_opened_by_the_operator_not_the_tablet(): void
    {
        CounterOperator::set($this->manager);

        Livewire::test(TillSession::class)->call('submitReweigh');

        $this->assertSame($this->manager->id, StockTake::query()->withoutGlobalScopes()->sole()->opened_by);
    }

    public function test_whos_inside_check_out_is_the_operators_act(): void
    {
        $member = $this->member();
        $checkIn = (new CheckInMember)->handle($member, $this->location, ['operator_id' => $this->staff->id]);

        // Nobody identified: the list still renders (the tablet may show it) but the write is refused.
        Livewire::test(WhosInside::class)->call('checkOut', $checkIn->id)->assertForbidden();
        $this->assertNull($checkIn->fresh()->checked_out_at);

        CounterOperator::set($this->staff); // staff hold checkin.manage
        Livewire::test(WhosInside::class)->call('checkOut', $checkIn->id);
        $this->assertNotNull($checkIn->fresh()->checked_out_at);
    }

    public function test_the_counter_home_shows_the_takings_only_to_an_operator_who_may_see_money(): void
    {
        CounterOperator::set($this->staff);
        $this->assertFalse(Livewire::test(CounterHome::class)->instance()->canSeeTakings(), 'a staff PIN saw the owner tablet\'s takings');

        CounterOperator::set($this->manager);
        $this->assertSame(
            $this->manager->can('reports.view') || $this->manager->can('reports.view.all'),
            Livewire::test(CounterHome::class)->instance()->canSeeTakings(),
        );
    }

    public function test_the_view_flags_follow_the_operator(): void
    {
        CounterOperator::set($this->staff);
        $this->assertFalse(Livewire::test(TillSession::class)->instance()->canBankCash(), 'staff inherited cash.bank from the owner tablet');

        CounterOperator::set($this->manager);
        $this->assertSame($this->manager->can('cash.bank'), Livewire::test(TillSession::class)->instance()->canBankCash());
    }
}
