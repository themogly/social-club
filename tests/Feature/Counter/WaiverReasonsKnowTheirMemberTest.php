<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 229 — the waiver's reasons, on the two hosts that never saw them.
 *
 * The owner, on the door with the waiver open: *"if you click member at another club then click another
 * reason it lets you select both options and can't unselect — it should toggle."* Two defects sat under that
 * one report, and this file covers the second — the one his screenshot could not show.
 *
 * **219's reasons are computed at RENDER time.** `waiveReasonOptions()` read `$feeMemberId`, which is Socios'
 * property; the door and the POS hold their member in `$memberId`. Prompt 211 met this exact host mismatch
 * and bridged it in the concern — and 219 bridged the fee ACTIONS the same way — but nothing bridged the
 * read path. So at the door a fresh open offered ONE option ("Otro motivo"), and the structured reasons
 * appeared only after some other action had happened to set `$feeMemberId`. Which reasons an operator saw
 * depended on what they had clicked beforehand.
 *
 * The first defect (radios with no `name`) is markup and is asserted structurally below; the browser half —
 * that clicking B leaves exactly one lit, immediately, without waiting on a round trip — is
 * `measure-waiver-reasons.mjs`, because native radio exclusivity is a browser behaviour and nothing else can
 * see it.
 */
class WaiverReasonsKnowTheirMemberTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->otherSede = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(Role $role = Role::MANAGER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id, $this->otherSede->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        // The counter resolves its own working sede from the session, not from the admin scope — without it
        // `resolveLocation()` is null and the "active elsewhere" reason cannot be computed at all.
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    /** A socio the record can justify BOTH structured reasons for: therapeutic, and active at the other sede. */
    private function memberOwingBothReasons(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(40), 'carencia_ends_at' => now()->subMonth(),
            'is_therapeutic' => true,
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);

        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);

        // Here: an ACTIVE membership with an outstanding fee — the thing being waived.
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2500,
        ]);

        // …and ACTIVE elsewhere in the club, which is 203's case and 219's second structured reason.
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->otherSede->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /** @return array<string, Testable> the same waiver, freshly opened, on each of the three hosts */
    private function freshlyOpenedOnEveryHost(Member $member): array
    {
        return [
            'door' => Livewire::test(CheckInScreen::class)->call('selectMember', $member->id)->call('toggleWaive'),
            'pos' => Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->call('toggleWaive'),
            'socios' => Livewire::test(MembershipCounter::class)->call('selectMember', $member->id)->call('toggleWaive'),
        ];
    }

    // --- Defect 2: the options were member-blind until an action bridged the member ----------------

    /**
     * **A fresh open offers all three reasons and preselects the suggested one — on every host.**
     *
     * Fails against `dc97f83` on the door and the POS, where a fresh open renders "Otro motivo" alone and
     * nothing is selected.
     */
    public function test_a_fresh_open_offers_the_record_backed_reasons_everywhere(): void
    {
        $this->operator();
        $member = $this->memberOwingBothReasons();

        foreach ($this->freshlyOpenedOnEveryHost($member) as $host => $component) {
            $options = collect($component->instance()->waiveReasonOptions())->pluck('value')->all();

            $this->assertSame(['THERAPEUTIC', 'OTHER_SEDE', 'OTHER'], $options, "{$host}: the structured reasons are missing");

            // …and 219's record-backed default is chosen, so the operator confirms rather than picks.
            $component->assertSet('waiveReason', 'THERAPEUTIC');

            $html = $component->html();
            $this->assertStringContainsString('data-waive-reason="THERAPEUTIC"', $html, "{$host}: the therapeutic reason is not rendered");
            $this->assertStringContainsString('data-waive-reason="OTHER_SEDE"', $html, "{$host}: the other-sede reason is not rendered");
        }
    }

    /** A socio the record justifies nothing for still gets the free-text route, and nothing preselected. */
    public function test_a_member_with_no_record_backed_reason_gets_only_free_text(): void
    {
        $this->operator();

        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(40), 'carencia_ends_at' => now()->subMonth(), 'is_therapeutic' => false,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2500,
        ]);

        foreach ($this->freshlyOpenedOnEveryHost($member) as $host => $component) {
            $this->assertSame(['OTHER'], collect($component->instance()->waiveReasonOptions())->pluck('value')->all(), "{$host}: unjustified reasons were offered");
            $component->assertSet('waiveReason', '', "{$host}: something was preselected with nothing to back it");
        }
    }

    // --- Defect 1: the radios are one group -------------------------------------------------------

    /** **Every radio in the waiver shares one `name`** — on every host, per render. */
    public function test_the_reason_radios_share_one_name_on_every_host(): void
    {
        $this->operator();
        $member = $this->memberOwingBothReasons();

        foreach ($this->freshlyOpenedOnEveryHost($member) as $host => $component) {
            $html = $component->html();

            preg_match_all('/<input[^>]*data-waive-reason="[A-Z_]+"[^>]*>/', $html, $inputs);
            $this->assertCount(3, $inputs[0], "{$host}: expected three reason radios");

            $names = [];
            foreach ($inputs[0] as $input) {
                preg_match('/\bname="([^"]+)"/', $input, $name);
                $this->assertNotEmpty($name, "{$host}: a reason radio has no name — it is a group of one and cannot be exclusive");
                $names[] = $name[1];
            }

            $this->assertCount(1, array_unique($names), "{$host}: the reason radios are in more than one group");
            $this->assertStringStartsWith('waive-reason-', $names[0], "{$host}: the group is not scoped to the component");
        }
    }

    /** Two hosts on one page cannot share a group — the scope is the component instance. */
    public function test_two_hosts_do_not_collide(): void
    {
        $this->operator();
        $member = $this->memberOwingBothReasons();

        $hosts = $this->freshlyOpenedOnEveryHost($member);

        $group = function (Testable $component): string {
            preg_match('/name="(waive-reason-[^"]+)"/', $component->html(), $m);

            return $m[1] ?? '';
        };

        $names = array_map($group, $hosts);

        $this->assertCount(count($names), array_unique($names), 'two hosts rendered the same radio group name');
    }

    /** The keyed loop: a list that changes between renders must not be morphed by position. */
    public function test_the_reason_loop_is_keyed(): void
    {
        $partial = (string) file_get_contents(resource_path('views/livewire/counter/partials/fee-waiver.blade.php'));

        $this->assertMatchesRegularExpression('/wire:key="\{\{ \$waiveGroup \}\}-\{\{ \$option\[.value.\] \}\}"/', $partial, 'the reason loop is unkeyed');
    }

    // --- What is recorded is what was selected ----------------------------------------------------

    /** Switching reasons and waiving records the reason that is visibly selected — on every host. */
    public function test_a_waive_after_switching_records_the_visible_reason(): void
    {
        $operator = $this->operator();

        foreach (['door', 'pos', 'socios'] as $host) {
            $member = $this->memberOwingBothReasons();

            $component = match ($host) {
                'door' => Livewire::test(CheckInScreen::class)->call('selectMember', $member->id),
                'pos' => Livewire::test(DispensaryPos::class)->call('selectMember', $member->id),
                default => Livewire::test(MembershipCounter::class)->call('selectMember', $member->id),
            };

            // Open (THERAPEUTIC preselected), then switch to the other structured reason and waive.
            $component->call('toggleWaive')
                ->assertSet('waiveReason', 'THERAPEUTIC')
                ->set('waiveReason', 'OTHER_SEDE')
                ->call('waiveFee');

            $payment = MembershipFeePayment::query()->latest('id')->firstOrFail();

            $this->assertSame('WAIVED', $payment->method->value, "{$host}: the waive did not record a WAIVED row");
            $this->assertSame(__('Socio en otra sede'), $payment->reason, "{$host}: the recorded reason is not the one selected");
            $this->assertSame($operator->id, $payment->recorded_by, "{$host}: the waive is not attributed to the operator");
        }
    }

    /** The permission guard is untouched: no `membership.fee.waive`, no waiver anywhere. */
    public function test_the_waiver_still_needs_its_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('membership.fee.collect');
        $user->givePermissionTo('checkin.manage');
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        $html = Livewire::test(CheckInScreen::class)->call('selectMember', $this->memberOwingBothReasons()->id)->html();

        $this->assertStringNotContainsString('data-fee-waive-toggle', $html);
    }
}
