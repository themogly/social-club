<?php

namespace Tests\Feature\Members;

use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\OpenTill;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\MembershipsRelationManager;
use App\Models\AuditLog;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ManagerApproval;
use App\Support\MembershipExpiry;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 325 (first issued as 322) — Ben: "I can't edit the membership after it's created." A membership decides who
 * may be dispensed to and carries money, so the answer is four SPECIFIC, audited corrections — Cambiar tarifa, Corregir
 * fechas, Cobrar / Condonar cuota, Anular — each through one writer, each with a reason. No general edit form.
 */
class MembershipCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $dreamGreen;

    private Location $greenhouse;

    private User $owner;

    private Member $member;

    private MembershipTier $locals;

    private MembershipTier $premium;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->dreamGreen = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green']);
        $this->greenhouse = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'DG Greenhouse']);
        app(ActiveScope::class)->setLocation($this->dreamGreen->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->dreamGreen->id, $this->greenhouse->id]);
        $this->actingAs($this->owner);

        $this->locals = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Locals', 'default_fee_cents' => 1000, 'active' => true]);
        $this->premium = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Premium', 'default_fee_cents' => 2500, 'active' => true]);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth()]);
        $this->membership = $this->membershipAt($this->dreamGreen);
    }

    private function membershipAt(Location $location, int $fee = 1000): Membership
    {
        return Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $location->id,
            'tier_id' => $this->locals->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => $fee, 'starts_at' => now()->subMonths(2), 'expires_at' => now()->addMonths(10)]);
    }

    private function table(): Testable
    {
        return Livewire::test(MembershipsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);
    }

    private function pay(Membership $membership, int $cents): void
    {
        (new RecordFeePayment)->handle($membership, $cents, FeePaymentMethod::CARD, []); // money taken, not a waiver
    }

    // --- 1. Cambiar tarifa ------------------------------------------------------------------------------------------------------

    public function test_changing_tier_while_the_fee_is_outstanding_changes_what_is_owed(): void
    {
        $this->table()->callTableAction('changeTier', $this->membership, ['tier_id' => $this->premium->id, 'reason' => 'Se equivocó de tarifa'])->assertHasNoTableActionErrors();

        $fresh = $this->membership->fresh();
        $this->assertSame($this->premium->id, $fresh->tier_id);
        $this->assertSame(2500, $fresh->fee_cents->cents);
        $audit = AuditLog::query()->where('action', 'membership.tier.changed')->sole();
        $this->assertSame([$this->locals->id, $this->premium->id], [$audit->before['tier_id'], $audit->after['tier_id']]);
        $this->assertSame('Se equivocó de tarifa', $audit->after['reason']);
    }

    public function test_changing_tier_after_payment_keeps_the_paid_fee_and_says_so(): void
    {
        $this->pay($this->membership, 1000);

        $this->table()->callTableAction('changeTier', $this->membership, ['tier_id' => $this->premium->id, 'reason' => 'Cambio de tarifa'])
            ->assertNotified(__('La cuota ya está pagada; cobra o devuelve la diferencia desde la caja si corresponde.'));

        $fresh = $this->membership->fresh();
        $this->assertSame($this->premium->id, $fresh->tier_id);
        $this->assertSame(1000, $fresh->fee_cents->cents, 'a paid fee was changed');
        $this->assertSame(1, AuditLog::query()->where('action', 'membership.tier.changed')->count());
    }

    // --- 2. Corregir fechas --------------------------------------------------------------------------------------------------------

    public function test_correcting_dates_recomputes_the_status_warns_and_refuses_an_expiry_before_the_start(): void
    {
        $this->table()->callTableAction('correctDates', $this->membership, ['starts_at' => now()->subYear()->toDateString(), 'expires_at' => now()->addDays(5)->toDateString(), 'reason' => 'Fecha mal puesta'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(MembershipStatus::EXPIRING_SOON, $this->membership->fresh()->status);
        $this->assertSame(MembershipExpiry::statusOn($this->membership->fresh()->expires_at, now()), $this->membership->fresh()->status);

        $this->table()->mountTableAction('correctDates', $this->membership)
            ->setTableActionData(['starts_at' => now()->subYear()->toDateString(), 'expires_at' => now()->subDay()->toDateString(), 'reason' => 'x'])
            ->assertMountedActionModalSee(__('Con estas fechas la membresía no estará activa hoy.'));
        $this->table()->mountTableAction('correctDates', $this->membership)
            ->setTableActionData(['starts_at' => now()->subYear()->toDateString(), 'expires_at' => now()->addYear()->toDateString(), 'reason' => 'x'])
            ->assertMountedActionModalDontSee(__('Con estas fechas la membresía no estará activa hoy.'));

        $this->table()->callTableAction('correctDates', $this->membership, ['starts_at' => now()->toDateString(), 'expires_at' => now()->subDay()->toDateString(), 'reason' => 'x'])
            ->assertHasTableActionErrors(['expires_at']);

        $this->table()->callTableAction('correctDates', $this->membership, ['starts_at' => now()->subYear()->toDateString(), 'expires_at' => now()->subDay()->toDateString(), 'reason' => 'Caducó'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(MembershipStatus::LAPSED, $this->membership->fresh()->status);
        $this->assertSame(2, AuditLog::query()->where('action', 'membership.dates.corrected')->count());
    }

    public function test_the_sweep_and_the_corrected_status_agree(): void
    {
        foreach ([-3, 1, 10, 29, 31, 200] as $days) {
            $membership = $this->membershipAt($this->greenhouse);
            $membership->forceFill(['expires_at' => now()->addDays($days)])->saveQuietly();
            $this->artisan('memberships:sweep')->assertSuccessful();

            $this->assertSame(MembershipExpiry::statusOn(now()->addDays($days), now()), $membership->fresh()->status, "{$days} days");
        }
    }

    // --- 3. Cobrar / Condonar cuota ---------------------------------------------------------------------------------------------------

    public function test_collecting_in_the_panel_is_the_counters_payment_and_cash_needs_an_open_till(): void
    {
        $this->table()->callTableAction('collectFee', $this->membership, ['amount' => '10', 'method' => 'CASH'])
            ->assertNotified(__('Abre la caja de :sede para cobrar en efectivo, o cobra desde el monedero.', ['sede' => 'Dream Green']));
        $this->assertSame(0, MembershipFeePayment::query()->count());

        $session = (new OpenTill)->handle($this->dreamGreen, 'POS-1', 5000);
        $this->table()->callTableAction('collectFee', $this->membership, ['amount' => '10', 'method' => 'CASH'])->assertHasNoTableActionErrors();

        $payment = MembershipFeePayment::query()->sole();
        $this->assertSame([1000, FeePaymentMethod::CASH, $session->id], [$payment->amount_cents->cents, $payment->method, $payment->till_session_id]);
        $this->table()->assertTableActionHidden('collectFee', $this->membership->fresh())->assertTableActionHidden('waiveFee', $this->membership->fresh());
    }

    public function test_waiving_in_the_panel_writes_the_counters_waiver_with_its_reason(): void
    {
        // Prompt 356 — this panel user holds `reasons.optional`: no reason field is shown, and the writer records
        // «Aprobado por responsable» (whatever a crafted payload says).
        $this->table()->callTableAction('waiveFee', $this->membership, ['waive_reason' => 'OTHER', 'waive_reason_text' => 'Alta duplicada'])->assertHasNoTableActionErrors();

        $waiver = MembershipFeePayment::query()->sole();
        $this->assertSame([1000, FeePaymentMethod::WAIVED, ManagerApproval::reason()], [$waiver->amount_cents->cents, $waiver->method, $waiver->reason]);
        $this->assertSame(1, AuditLog::query()->where('action', 'membership.fee.waived')->count());
    }

    // --- 4. Anular ---------------------------------------------------------------------------------------------------------------------

    public function test_cancelling_an_unpaid_membership_keeps_the_row_marked_cancelled(): void
    {
        $duplicate = $this->membershipAt($this->greenhouse);

        $this->table()->callTableAction('cancel', $duplicate, ['reason' => 'Duplicada'])->assertHasNoTableActionErrors();

        $this->assertSame(MembershipStatus::CANCELLED, $duplicate->fresh()->status);
        $this->assertSame(2, Membership::query()->withoutGlobalScopes()->where('member_id', $this->member->id)->count(), 'the row was deleted');
        $this->assertSame('Duplicada', AuditLog::query()->where('action', 'membership.cancelled')->sole()->after['reason']);
        $this->assertNull($this->member->fresh()->activeMembershipAt($this->greenhouse), 'a cancelled membership still allows dispensing');
    }

    public function test_a_paid_membership_is_only_cancelled_keeping_the_paid_fee(): void
    {
        $this->pay($this->membership, 1000);

        $this->table()->callTableAction('cancel', $this->membership, ['reason' => 'Error'])->assertHasTableActionErrors(['keep_paid_fee']);
        $this->assertSame(MembershipStatus::ACTIVE, $this->membership->fresh()->status);

        $this->table()->callTableAction('cancel', $this->membership, ['reason' => 'Error', 'keep_paid_fee' => true])->assertHasNoTableActionErrors();
        $this->assertSame(MembershipStatus::CANCELLED, $this->membership->fresh()->status);
        $this->assertSame(1, MembershipFeePayment::query()->count(), 'money moved on cancel');
        $this->assertTrue((bool) AuditLog::query()->where('action', 'membership.cancelled')->sole()->after['kept_paid_fee']);
    }

    public function test_recent_dispensations_refuse_a_cancel_until_confirmed(): void
    {
        Dispensation::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->dreamGreen->id, 'dispensed_at' => now()->subDays(3)]);

        $this->table()->callTableAction('cancel', $this->membership, ['reason' => 'Error'])->assertHasTableActionErrors(['confirm_recent_dispensations']);
        $this->assertSame(MembershipStatus::ACTIVE, $this->membership->fresh()->status);

        $this->table()->callTableAction('cancel', $this->membership, ['reason' => 'Error', 'confirm_recent_dispensations' => true])->assertHasNoTableActionErrors();
        $this->assertSame(MembershipStatus::CANCELLED, $this->membership->fresh()->status);
    }

    // --- 5. Permissions -------------------------------------------------------------------------------------------------------------------

    public function test_the_corrections_need_membership_manage_and_a_manager_only_at_their_sedes(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->dreamGreen->id]);
        $this->actingAs($staff);
        foreach (['changeTier', 'correctDates', 'cancel'] as $action) {
            $this->table()->assertTableActionHidden($action, $this->membership);
        }

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->dreamGreen->id]);
        $this->actingAs($manager);
        $elsewhere = $this->membershipAt($this->greenhouse);
        $this->table()->assertTableActionVisible('changeTier', $this->membership)->assertTableActionHidden('changeTier', $elsewhere)
            ->assertTableActionHidden('cancel', $elsewhere)->assertTableActionHidden('correctDates', $elsewhere);
    }
}
