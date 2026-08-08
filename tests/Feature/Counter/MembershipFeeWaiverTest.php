<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\OpenTill;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\AuditLog;
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
use App\Support\Period;
use App\Support\PermissionDrift;
use App\Support\Permissions;
use App\Support\TillSummary;
use App\ViewModels\DashboardCharts;
use App\ViewModels\Reports\FinancialReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Prompt 219 — waiving a fee is a recorded decision, not a payment that never happens.
 *
 * The owner, collecting a fee at the door: *"need an option to waive the fee — often they waive it if they
 * are medical, or if they have a membership at another club."*
 *
 * Before this, the only ways out of an outstanding fee were collecting it or a manager quietly not chasing
 * it. A club that routinely waives — and this one does — had no way to say so, so the register showed members
 * permanently "owing" money the club had decided not to take, and the door nagged about it for ever.
 *
 * **The mechanism is a `MembershipFeePayment` with method `WAIVED`, not an edit to the fee.** `owedCents()`
 * (and `VerdictRemedy`'s copy of the same sum) totals `amount_cents` regardless of method, so one row clears
 * the debt everywhere at once — with no second write path and no consumer changed. The tier's fee stays the
 * fact: the club charged €20 and chose to forgo it, which is a different truth from "the fee was €0".
 */
class MembershipFeeWaiverTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 50]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(Role $role = Role::STAFF, bool $openTill = false): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        if ($openTill && ! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    /** A member with an ACTIVE membership at this sede and €20 outstanding. */
    private function memberOwing(int $feeCents = 2000, bool $therapeutic = false): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
            'is_therapeutic' => $therapeutic,
        ]);

        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => $feeCents])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
            'fee_cents' => $feeCents,
        ]);

        return $member;
    }

    private function owed(Member $member): int
    {
        $membership = $member->fresh()->activeMembershipAt($this->location);
        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        return max(0, $membership->fee_cents->cents - $paid);
    }

    // --- The reported need, end to end, from all three hosts ------------------------------------

    /**
     * Waiving clears the fee everywhere, from each of the three screens that collect it.
     *
     * **Fails against `main`**: no waive path exists there. Asserted on the DEBT and on the verdict, not on a
     * flash message — the claim is that one row clears the door notice, the `unpaid_fee` rule and the fee
     * panel at once, because they all read the same sum.
     */
    public function test_waiving_clears_the_fee_from_every_host(): void
    {
        foreach ([MembershipCounter::class, CheckInScreen::class, DispensaryPos::class] as $screen) {
            $this->operator();
            $member = $this->memberOwing();

            $this->assertSame(2000, $this->owed($member), "[{$screen}] precondition: the fee is outstanding");

            Livewire::test($screen)
                ->call('selectMember', $member->id)
                ->call('toggleWaive')
                ->set('waiveReason', 'OTHER')
                ->set('waiveReasonText', 'Acordado con el responsable')
                ->call('waiveFee');

            $this->assertSame(0, $this->owed($member), "[{$screen}] the fee is still outstanding after waiving");

            // The verdict that nags at the door reads the same sum, so it clears too.
            $verdict = (new ResolveMemberEligibility)
                ->handle($member->fresh(), $this->location, 'counter');

            foreach ($verdict->rules as $rule) {
                if ($rule['rule'] === 'unpaid_fee') {
                    $this->assertTrue($rule['satisfied'], "[{$screen}] the unpaid-fee verdict still fires");
                }
            }
        }
    }

    /** A waiver needs no open till — it moves no cash. A CASH fee still does. */
    public function test_a_waiver_needs_no_open_till_but_a_cash_fee_still_does(): void
    {
        $this->operator(openTill: false);
        $member = $this->memberOwing();

        $this->assertSame(0, TillSession::query()->withoutGlobalScopes()->count(), 'precondition: no drawer');

        // Cash is refused without a drawer, exactly as before.
        Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->set('feeAmount', '20,00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee');
        $this->assertSame(2000, $this->owed($member), 'a cash fee was taken with no drawer open');

        // The waiver is not.
        Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->call('toggleWaive')
            ->set('waiveReason', 'OTHER')
            ->set('waiveReasonText', 'Sin caja abierta')
            ->call('waiveFee');

        $this->assertSame(0, $this->owed($member), 'the waiver was refused for want of a drawer');

        // …and it attaches to no session, so the arqueo has nothing to reconcile.
        $waiver = MembershipFeePayment::query()->where('method', FeePaymentMethod::WAIVED->value)->sole();
        $this->assertNull($waiver->till_session_id, 'a waiver attached itself to a drawer');
    }

    /** A partial waiver leaves the remainder collectable, and the mixed history reads correctly. */
    public function test_a_partial_waiver_leaves_the_rest_collectable(): void
    {
        $this->operator(openTill: true);
        $member = $this->memberOwing();

        Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->set('feeAmount', '10,00')
            ->call('toggleWaive')
            ->set('waiveReason', 'OTHER')
            ->set('waiveReasonText', 'Mitad condonada')
            ->call('waiveFee');

        $this->assertSame(1000, $this->owed($member), 'the partial waiver did not leave a remainder');

        Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->set('feeAmount', '10,00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee');

        $this->assertSame(0, $this->owed($member));

        $methods = MembershipFeePayment::query()->pluck('method')->map(fn ($m) => $m instanceof FeePaymentMethod ? $m->value : $m)->sort()->values()->all();
        $this->assertSame(['CASH', 'WAIVED'], $methods, 'the mixed history is wrong');
    }

    // --- Who may, and what is recorded ----------------------------------------------------------

    /** STAFF may waive — the owner's decision, over mirroring the manager-only fee override. */
    public function test_staff_may_waive(): void
    {
        $staff = $this->operator(Role::STAFF);

        $this->assertTrue($staff->can('membership.fee.waive'));
        $this->assertContains('membership.fee.waive', Permissions::for(Role::STAFF));
        $this->assertContains('membership.fee.waive', Permissions::for(Role::MANAGER));
        $this->assertContains('membership.fee.waive', Permissions::for(Role::OWNER));
    }

    /** An empty reason is refused SERVER-SIDE — at the screen and at the writer, not just in the UI. */
    public function test_an_empty_reason_is_refused_server_side(): void
    {
        $this->operator();
        $member = $this->memberOwing();

        Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->call('toggleWaive')
            ->set('waiveReason', 'OTHER')
            ->set('waiveReasonText', '   ')
            ->call('waiveFee');

        $this->assertSame(2000, $this->owed($member), 'a reasonless waiver was recorded');

        // …and the WRITER refuses it too, so no future caller can skip the rule.
        $membership = $member->fresh()->activeMembershipAt($this->location);
        $this->expectException(InvalidArgumentException::class);
        (new RecordFeePayment)->handle($membership, 2000, FeePaymentMethod::WAIVED);
    }

    /** The audit row carries member, membership, amount, reason and operator. */
    public function test_the_waiver_is_audited(): void
    {
        $operator = $this->operator();
        $member = $this->memberOwing();

        Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->call('toggleWaive')
            ->set('waiveReason', 'OTHER')
            ->set('waiveReasonText', 'Acuerdo de la junta')
            ->call('waiveFee');

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'membership.fee.waived')->sole();
        $meta = (array) $log->after;

        $this->assertSame($member->id, $meta['member_id']);
        $this->assertSame(2000, $meta['amount_cents']);
        $this->assertSame('Acuerdo de la junta', $meta['reason']);
        $this->assertSame($operator->id, $meta['operator_id']);

        // …and the reason is on the payment row itself, which is what a report reads.
        $this->assertSame('Acuerdo de la junta', MembershipFeePayment::query()->sole()->reason);
    }

    // --- The structured reasons, backed by the record --------------------------------------------

    /** Terapéutico is offered only when the member's own flag is set — and is then the default. */
    public function test_the_therapeutic_reason_is_offered_only_when_the_flag_is_set(): void
    {
        $this->operator();

        $plain = $this->memberOwing();
        $options = Livewire::test(MembershipCounter::class)->call('selectMember', $plain->id)
            ->instance()->waiveReasonOptions();
        $this->assertSame(['OTHER'], array_column($options, 'value'), 'therapeutic was offered without the flag');

        $therapeutic = $this->memberOwing(therapeutic: true);
        $component = Livewire::test(MembershipCounter::class)->call('selectMember', $therapeutic->id)->call('toggleWaive');

        $this->assertContains('THERAPEUTIC', array_column($component->instance()->waiveReasonOptions(), 'value'));
        $component->assertSet('waiveReason', 'THERAPEUTIC', 'the record-backed reason was not pre-selected');

        // …and it is pre-selected in the MARKUP too, not only in the state: `wire:model` binds on the next
        // round trip, so without `@checked` the operator's first paint shows no reason chosen.
        $this->assertMatchesRegularExpression(
            '/data-waive-reason="THERAPEUTIC"[^>]*checked/s',
            $component->html(),
            'the default reason is not checked on first paint',
        );
    }

    /** Socio en otra sede is offered only when an ACTIVE membership exists elsewhere (203's case). */
    public function test_the_other_sede_reason_is_offered_only_when_one_exists(): void
    {
        $this->operator();
        $member = $this->memberOwing();

        $options = Livewire::test(MembershipCounter::class)->call('selectMember', $member->id)
            ->instance()->waiveReasonOptions();
        $this->assertNotContains('OTHER_SEDE', array_column($options, 'value'));

        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $other->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);

        $options = Livewire::test(MembershipCounter::class)->call('selectMember', $member->id)
            ->instance()->waiveReasonOptions();
        $this->assertContains('OTHER_SEDE', array_column($options, 'value'));
    }

    // --- The guard for the class: a waiver is never money -----------------------------------------

    /**
     * **A waived amount enters no revenue figure, anywhere.**
     *
     * Seeded as one CASH, one WALLET and one WAIVED payment, and asserted by iterating the surfaces that sum
     * `MembershipFeePayment` rather than the two anyone happened to check. Forgone income is a real governance
     * fact and gets its own line; it is never a share of a figure that says the club took money.
     */
    public function test_a_waiver_is_never_counted_as_money(): void
    {
        $this->operator(Role::OWNER, openTill: true);

        // CASH and BANK are both revenue; BANK rather than WALLET because a wallet fee posts a ledger
        // movement and would need a funded wallet — a different mechanism's rules, and not what is under test.
        foreach ([
            [FeePaymentMethod::CASH, 1100],
            [FeePaymentMethod::BANK, 1300],
            [FeePaymentMethod::WAIVED, 1700],
        ] as [$method, $cents]) {
            $member = $this->memberOwing($cents);
            $membership = $member->activeMembershipAt($this->location);

            (new RecordFeePayment)->handle($membership, $cents, $method, array_filter([
                'reason' => $method === FeePaymentMethod::WAIVED ? 'Terapéutico' : null,
            ]));
        }

        $report = new FinancialReport($this->org->id, [$this->location->id], Period::thisMonth());
        $tables = collect($report->tables())->keyBy(fn ($t) => $t->key);

        // The revenue series: cash + wallet only.
        $takings = $tables['takings'];
        $this->assertSame(1100 + 1300, (int) $takings->totals['cuotas'], 'the waived amount was counted as fee revenue');
        $this->assertSame(1100 + 1300, (int) $takings->totals['ingresos'], 'the waived amount reached total income');

        // The payment-method mix: no row of any method contains it.
        $byMethod = $tables['methods'];
        foreach ($byMethod->rows as $row) {
            $this->assertNotSame(1700, (int) $row['importe'], 'a waiver appeared as a payment method');
        }
        $this->assertSame(1100 + 1300, array_sum(array_column($byMethod->rows, 'importe')), 'the method mix includes the waiver');

        // …and it appears where forgone income belongs, with its reason.
        $waived = $tables['waived'];
        $this->assertSame(1700, (int) $waived->totals['importe'], 'the waived line is missing the amount');
        $this->assertSame('Terapéutico', $waived->rows[0]['motivo']);

        // The dashboard's own cuotas series reads the same rule.
        $charts = new DashboardCharts($this->org->id, [$this->location->id], Period::thisMonth());
        $income = $charts->incomeByPeriod(Period::thisMonth());
        $cuotas = collect($income['cuotas'] ?? $income)->flatten()->sum();
        $this->assertSame(1100 + 1300, (int) $cuotas, 'the dashboard counted the waiver as income');
    }

    /** The arqueo is untouched: *Cuotas en efectivo* still totals only CASH. */
    public function test_the_arqueo_still_totals_only_cash_fees(): void
    {
        $this->operator(Role::OWNER, openTill: true);
        $session = TillSession::query()->withoutGlobalScopes()->sole();

        $cash = $this->memberOwing(1100);
        (new RecordFeePayment)->handle($cash->activeMembershipAt($this->location), 1100, FeePaymentMethod::CASH, ['till_session_id' => $session->id]);

        $waived = $this->memberOwing(1700);
        (new RecordFeePayment)->handle($waived->activeMembershipAt($this->location), 1700, FeePaymentMethod::WAIVED, [
            'till_session_id' => $session->id,   // offered, and ignored: a waiver moves no cash
            'reason' => 'Terapéutico',
        ]);

        $breakdown = TillSummary::breakdown($session->fresh());

        // The arqueo counts CASH fees and nothing else — a waiver moves no drawer, so the expected figure
        // is unchanged by one.
        $this->assertSame(1100, (int) $breakdown['fees_cash'], 'the arqueo moved with a waiver');
    }

    // --- How it reaches the live club --------------------------------------------------------------

    /**
     * 214's sync carries `membership.fee.waive` to a database seeded without it.
     *
     * Asserted because that is how the permission reaches the live club — a matrix change in code that never
     * arrives is prompt 214's whole finding, and this branch adds one.
     */
    public function test_the_sync_carries_the_new_permission_to_an_installed_database(): void
    {
        // A database as it stood before this branch.
        foreach (Role::cases() as $case) {
            SpatieRole::query()->where('name', $case->value)->firstOrFail()->revokePermissionTo('membership.fee.waive');
        }
        Permission::query()->where('name', 'membership.fee.waive')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $staff = $this->operator(Role::STAFF);
        $this->assertFalse($staff->can('membership.fee.waive'), 'precondition: the old matrix lacks it');
        $this->assertFalse(PermissionDrift::report()['in_sync']);

        $this->assertSame(0, Artisan::call('csc:sync-permissions'));

        $this->assertTrue($staff->fresh()->can('membership.fee.waive'), 'the sync did not carry the permission');
        $this->assertTrue(PermissionDrift::report()['in_sync']);
    }
}
