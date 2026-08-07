<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Exceptions\DuplicateMembershipException;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\StockCeiling;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 203 — a member with no membership at this sede was a dead end.
 *
 * The owner's screenshot is the whole argument: **Caitlin Allen, M-00012, ACTIVE**, and underneath *"Sin
 * membresía activa en esta sede"* — with the verdict panel below it saying *"renueva su cuota desde su
 * ficha"*. **There was no control on the screen that did that.** The three Actions that would
 * (`EnrolMembership`, `RenewMembership`, `TransferMembership`) were surfaced in exactly one place, the admin
 * panel, and STAFF hold no membership-management permission of any kind. So the screen's own remedy text
 * pointed a staff user at a door they cannot open, and with one person working on a Friday evening the
 * member is turned away.
 *
 * **What 203 opened, and what it deliberately did not.** A new `membership.enrol` permission (STAFF and
 * MANAGER) covers opening a membership AT THE SEDE YOU ARE WORKING AT, on the tier's default fee — the same
 * shape prompt 174 used for the alta, where the audited single-writer route is the open one. Fee overrides,
 * tier changes, suspensions, limits and **transfers between sedes** have not moved.
 */
class MembershipAtTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Location $otherSede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'capacity' => 20]);
        $this->otherSede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'capacity' => 20]);
    }

    private function operator(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function tier(int $feeCents = 2500, string $name = 'Estándar'): MembershipTier
    {
        return MembershipTier::factory()->create([
            'organisation_id' => $this->org->id, 'name' => $name, 'default_fee_cents' => $feeCents,
        ]);
    }

    private int $memberSeq = 0;

    /** An ACTIVE member of the club — the owner's case. Whether they hold a membership here is the variable. */
    private function member(): Member
    {
        // Sequenced: the member_no is unique per (org, no), and one test walks all three roles in a loop.
        $no = sprintf('M-%05d', 12 + $this->memberSeq++);

        return Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => 'Caitlin', 'last_name' => 'Allen', 'member_no' => $no,
            'date_of_birth' => now()->subYears(34), 'joined_at' => now()->subYears(2),
            'carencia_ends_at' => now()->subDay(), 'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
    }

    private function membership(Member $member, Location $location, MembershipTier $tier, MembershipStatus $status, ?int $feeCents = null): Membership
    {
        return Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => $tier->id, 'status' => $status,
            'fee_cents' => $feeCents ?? $tier->default_fee_cents->cents,
            'expires_at' => $status === MembershipStatus::ACTIVE ? now()->addYear() : now()->subMonth(),
        ]);
    }

    private function screen(Member $member): Testable
    {
        return Livewire::test(MembershipCounter::class)->call('selectFeeMember', $member->id);
    }

    // --- Case 1: lapsed here ------------------------------------------------------

    /**
     * The reported bug, end to end: an expired member is put right at the counter and can then be dispensed
     * to — by a STAFF user, holding only what STAFF hold.
     *
     * Fails against `main`: there is no `renewMembership` on the component at all.
     */
    public function test_a_lapsed_member_is_renewed_at_the_counter_and_becomes_dispensable(): void
    {
        $this->operator(Role::STAFF);
        $tier = $this->tier(2500);
        $member = $this->member();
        $lapsed = $this->membership($member, $this->location, $tier, MembershipStatus::LAPSED);

        $this->assertNull($member->activeMembershipAt($this->location), 'precondition: the dead end');

        $this->screen($member)->call('renewMembership')->assertSet('flashType', 'success');

        $restored = $lapsed->fresh();
        $this->assertSame(MembershipStatus::ACTIVE, $restored->status);
        $this->assertSame($lapsed->id, $restored->id, 'the SAME row — its fee history survives, it is not a second alta');
        $this->assertTrue($restored->expires_at->isFuture());

        // …and the thing the member actually came for: the counter verdict no longer blocks them.
        $verdict = (new ResolveMemberEligibility)->handle($member->fresh(), $this->location, 'counter');
        $membershipRule = collect($verdict->rules)->firstWhere('rule', 'membership');
        $this->assertTrue($membershipRule['satisfied'], 'the membership block must be gone');
    }

    /** A fee that somebody with `membership.fee.override` set is not quietly reset by a staff renewal. */
    public function test_a_non_default_fee_is_refused_at_the_counter_rather_than_overwritten(): void
    {
        $this->operator(Role::STAFF);
        $tier = $this->tier(2500);
        $member = $this->member();
        $lapsed = $this->membership($member, $this->location, $tier, MembershipStatus::LAPSED, feeCents: 0);

        $this->screen($member)
            ->call('renewMembership')
            ->assertSet('flashType', 'warning')
            ->assertSee(__('Esta membresía tiene una cuota especial. Renuévala desde el panel para conservarla.'));

        $this->assertSame(MembershipStatus::LAPSED, $lapsed->fresh()->status, 'nothing was renewed');
        $this->assertSame(0, $lapsed->fresh()->fee_cents->cents, 'and the negotiated fee is intact');
    }

    // --- Case 2: never enrolled here ----------------------------------------------

    public function test_a_member_with_no_membership_here_is_enrolled_at_the_counter(): void
    {
        $this->operator(Role::STAFF);
        $tier = $this->tier(2500);
        $member = $this->member();

        $this->screen($member)
            ->set('openTierId', $tier->id)
            ->call('enrolAtThisSede')
            ->assertSet('flashType', 'success');

        $created = $member->activeMembershipAt($this->location);
        $this->assertNotNull($created);
        $this->assertSame($tier->id, $created->tier_id);
        $this->assertSame(2500, $created->fee_cents->cents, 'the tier default, in cents — the counter has no fee box');
        $this->assertNull($created->fee_override_by, 'and it is not an override');
    }

    // --- Case 3: active at another sede -------------------------------------------

    /**
     * A member of Sede Norte standing at Sede Centro gets a SECOND membership. Norte is untouched.
     *
     * The decision, asserted rather than described: a transfer would move the row, taking a member off the
     * other sede's register and out of its stock ceiling, decided from a tablet at this one by somebody who
     * may not work there and does not hold `members.transfer`.
     */
    public function test_a_member_of_another_sede_is_enrolled_here_and_the_other_sede_is_untouched(): void
    {
        $this->operator(Role::STAFF);
        $tier = $this->tier(2500);
        $member = $this->member();
        $atNorte = $this->membership($member, $this->otherSede, $tier, MembershipStatus::ACTIVE);

        $ceilingBefore = StockCeiling::forLocation($this->otherSede);

        $this->screen($member)
            ->assertSee('Sede Norte')                      // the register fact that makes the case legible
            ->set('openTierId', $tier->id)
            ->call('enrolAtThisSede')
            ->assertSet('flashType', 'success');

        $this->assertNotNull($member->activeMembershipAt($this->location), 'enrolled here');

        $norte = $atNorte->fresh();
        $this->assertSame($this->otherSede->id, $norte->location_id, 'the other sede keeps its member — this is not a transfer');
        $this->assertSame(MembershipStatus::ACTIVE, $norte->status);
        $this->assertSame(
            $ceilingBefore['active_members'],
            StockCeiling::forLocation($this->otherSede)['active_members'],
            "the other sede's stock ceiling is unchanged",
        );
        $this->assertSame(2, $member->memberships()->withoutGlobalScopes()->count(), 'they hold two, deliberately');
    }

    // --- The guard, in the Action ------------------------------------------------

    /**
     * Double-submitting cannot create two memberships. Asserted against the ACTION, not the screen.
     *
     * `EnrolMembership` created a row unconditionally — no schema constraint, no check — which was fine
     * while its callers were a wizard, the panel and an import. A button on a tablet is none of those, and
     * the second row would count a second time toward this sede's stock ceiling.
     */
    public function test_the_action_refuses_a_second_active_membership_at_the_same_location(): void
    {
        $tier = $this->tier(2500);
        $member = $this->member();

        (new EnrolMembership)->handle($member, $this->location, $tier);

        $this->expectException(DuplicateMembershipException::class);
        (new EnrolMembership)->handle($member, $this->location, $tier);
    }

    /** And the screen surfaces that refusal rather than a stack trace, leaving exactly one row. */
    public function test_a_double_tap_at_the_counter_leaves_one_membership(): void
    {
        $this->operator(Role::STAFF);
        $tier = $this->tier(2500);
        $member = $this->member();

        $component = $this->screen($member)->set('openTierId', $tier->id);
        $component->call('enrolAtThisSede')->assertSet('flashType', 'success');
        $component->set('openTierId', $tier->id)->call('enrolAtThisSede')->assertSet('flashType', 'warning');

        $this->assertSame(1, $member->memberships()->withoutGlobalScopes()->count());
    }

    /** A lapsed row does NOT block a fresh enrolment — the import brings across strings of expired ones. */
    public function test_a_lapsed_membership_does_not_block_the_action(): void
    {
        $tier = $this->tier(2500);
        $member = $this->member();
        $this->membership($member, $this->location, $tier, MembershipStatus::LAPSED);

        $created = (new EnrolMembership)->handle($member, $this->location, $tier);

        $this->assertSame(MembershipStatus::ACTIVE, $created->status);
    }

    // --- Permission ---------------------------------------------------------------

    /**
     * Without `membership.enrol`: a clear refusal and a named next step, not a dead panel.
     *
     * The permissions are granted DIRECTLY rather than by revoking one from the STAFF role — spatie resolves
     * a role's permissions independently of the user's, so a revoke on the user would have changed nothing
     * and this test would have passed by accident against a screen that still showed the button.
     */
    public function test_a_user_without_the_permission_is_refused_clearly(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('membership.fee.collect', 'members.view', 'pos.use');
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        $tier = $this->tier(2500);
        $member = $this->member();
        $this->membership($member, $this->location, $tier, MembershipStatus::LAPSED);

        $component = $this->screen($member);

        $component->assertSee(__('Este socio necesita una membresía en esta sede. Pídeselo a un responsable: no tienes permiso para darla de alta.'));
        $this->assertStringNotContainsString('data-membership-renew', $component->html(), 'no button they cannot use');

        // And the server refuses it too — the copy is not the control.
        $component->call('renewMembership')->assertSet('flashType', 'error');
        $this->assertSame(MembershipStatus::LAPSED, $member->fresh()->memberships()->withoutGlobalScopes()->sole()->status);
    }

    /** STAFF hold it deliberately; the transfer permission is still manager-only. */
    public function test_staff_may_enrol_but_still_may_not_transfer(): void
    {
        $staff = $this->operator(Role::STAFF);

        $this->assertTrue($staff->can('membership.enrol'));
        $this->assertFalse($staff->can('members.transfer'), 'moving a membership between sedes stays manager-gated');
        $this->assertFalse($staff->can('membership.fee.override'), 'and the fee box has not moved either');
        $this->assertFalse($staff->can('members.create'));
    }

    // --- The fee that follows ------------------------------------------------------

    /**
     * Opening a membership and taking its fee are deliberately NOT one transaction (prompt 174's precedent).
     *
     * If the fee cannot be taken the membership still exists and is owed — an ordinary state this product
     * represents and this screen surfaces. Rolling back an admission over a payment failure would be worse.
     */
    public function test_the_enrolment_stands_when_the_cash_fee_is_refused_for_want_of_a_till(): void
    {
        $this->operator(Role::STAFF);
        $tier = $this->tier(2500);
        $member = $this->member();

        $component = $this->screen($member)->set('openTierId', $tier->id)->call('enrolAtThisSede');
        $component->assertSet('flashType', 'success');

        // No till open at this sede: a CASH fee is refused exactly as it always was.
        $component->set('feeAmount', '25,00')->set('feeMethod', 'CASH')->call('collectFee')
            ->assertSet('flashType', 'error')
            ->assertSee(__('No hay caja abierta: un cobro en efectivo debe registrarse en la caja.'));

        $this->assertNotNull($member->activeMembershipAt($this->location), 'the alta landed and stays landed');
        $this->assertSame(0, MembershipFeePayment::query()->withoutGlobalScopes()->count(), 'and nothing was collected');
    }

    /** With a drawer open, the fee that follows behaves exactly as fee collection does today. */
    public function test_the_fee_after_an_enrolment_records_the_real_amount_in_cents(): void
    {
        $this->operator(Role::STAFF);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $tier = $this->tier(2500);
        $member = $this->member();

        $this->screen($member)
            ->set('openTierId', $tier->id)->call('enrolAtThisSede')
            ->set('feeAmount', '25,00')->set('feeMethod', 'CASH')->call('collectFee')
            ->assertSet('flashType', 'success');

        $this->assertSame(2500, MembershipFeePayment::query()->withoutGlobalScopes()->sole()->amount_cents->cents);
    }

    // --- Part two: the record says who this is ------------------------------------

    /** Age, not date of birth — and joined, which is an ordinary register fact. */
    public function test_the_record_states_the_age_and_when_they_joined(): void
    {
        $this->operator(Role::STAFF);
        $member = $this->member();

        $html = $this->screen($member)->html();

        $this->assertStringContainsString('data-member-age', $html);
        $this->assertStringContainsString(__(':years años', ['years' => 34]), $html);
        $this->assertStringContainsString(__('Socio desde'), $html);
    }

    /**
     * The date of birth itself is NOT rendered, in any state, for any role.
     *
     * The line 203 drew: age is a derived fact that answers the counter's question — does this match the
     * card, are they plainly of age — while a date of birth is an identifier used for identity verification
     * everywhere else, printed on a tablet with the next socio behind them. Same reasoning 177 applied to
     * consumption history.
     */
    public function test_the_date_of_birth_itself_is_never_rendered(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $i => $role) {
            $this->operator($role);
            $member = $this->member();
            $dob = $member->date_of_birth;

            $html = $this->screen($member)->call('toggleHistory')->html();

            $this->assertStringNotContainsString($dob->format('d/m/Y'), $html, "{$role->value} can read the DOB");
            $this->assertStringNotContainsString($dob->format('Y-m-d'), $html, "{$role->value} can read the DOB");
        }
    }
}
