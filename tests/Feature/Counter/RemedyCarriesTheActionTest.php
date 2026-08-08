<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\EligibilityRule;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\Concerns\OpensMemberships;
use App\Livewire\Counter\DispensaryPos;
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
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\VerdictRemedy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 211 — 203 fixed the dead end on one screen; the dispensary still said "go to their record".
 *
 * Reported on `/counter/pos` with a member attached: *"if you pull in a member with no membership you can't
 * click on them to take you to add a membership — there should be an action button."*
 *
 * **This is 203's finding again, not a new one.** 203 closed exactly this dead end — a member with a lapsed or
 * absent membership, and a remedy that sent staff to a panel they hold no permission to act in. It closed it
 * **on the Socios screen**, granted `membership.enrol` to STAFF and MANAGER, and left the shared remedy string
 * untouched. So the fix existed one screen away while `VerdictRemedy` went on saying *"Renueva su cuota desde
 * su ficha"* — naming the place 203 was written **because** staff cannot use it.
 *
 * `VerdictRemedy::describe()` is read by the door, the dispensary and Socios, which is why the fix is there
 * and not in a screen's blade: patching the POS would have left Recepción saying the same wrong thing
 * tomorrow, and that is how this arrived twice already.
 */
class RemedyCarriesTheActionTest extends TestCase
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

    /**
     * Exactly what 203 granted: a counter operator who may dispense and may open a membership.
     *
     * `$mayEnrol: false` is built from individual permissions rather than by revoking one from the user,
     * because `membership.enrol` comes from the STAFF ROLE — a user-level revoke leaves the role grant in
     * place and the test would silently prove nothing.
     */
    private function operator(bool $mayEnrol = true): User
    {
        $user = User::factory()->create();

        if ($mayEnrol) {
            $user->assignRole(Role::STAFF->value);
        } else {
            $user->givePermissionTo('pos.use', 'checkin.manage', 'membership.fee.collect');
        }

        $this->assertSame($mayEnrol, $user->can('membership.enrol'), 'the fixture does not hold what it claims');

        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    /** An ACTIVE member of the club — the exact case in the report. */
    private function member(): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
    }

    private function tier(): MembershipTier
    {
        return MembershipTier::factory()->create([
            'organisation_id' => $this->org->id, 'default_fee_cents' => 2500,
        ]);
    }

    private function sellableGenetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'active' => true]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 500000, 'remaining_cg' => 500000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    /** The membership rule's remedy, as the operator on this terminal reads it. */
    private function membershipRemedy(Member $member, User $actor): array
    {
        $verdict = (new ResolveMemberEligibility)->handle($member, $this->location, 'counter');

        foreach ($verdict->rules as $rule) {
            if ($rule['rule'] === EligibilityRule::MEMBERSHIP->value) {
                return VerdictRemedy::describe($rule, $member, $this->location, $actor);
            }
        }

        $this->fail('the membership rule did not fire for a member with no membership here');
    }

    // --- The reported bug, end to end --------------------------------------------------------

    /**
     * A member with NO membership at this sede is resolved from the dispensary and then dispensed to, in one
     * flow, by a user holding only what 203 granted.
     *
     * **Fails against `main`**: there the remedy is a sentence naming the admin panel, with no action to
     * follow — `VerdictRemedy` has no `action` key at all.
     */
    public function test_a_member_with_no_membership_is_resolved_from_the_dispensary_and_then_dispensed_to(): void
    {
        $operator = $this->operator();
        $member = $this->member();
        $tier = $this->tier();
        $genetic = $this->sellableGenetic();

        // 1) The POS blocks, and now carries 203's own fix panel — on this screen, not one screen away.
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $pos->assertSee('data-membership-fix', false);

        $remedy = $this->membershipRemedy($member, $operator);
        $this->assertNotNull($remedy['action'], 'the remedy carries no action');
        $this->assertTrue($remedy['action']['inline'], 'the remedy sends the operator away instead of fixing it here');

        // 2) Put it right WITHOUT leaving the dispensary, through 203's audited route and gate.
        $pos->set('openTierId', $tier->id)->call('enrolAtThisSede');

        $this->assertNotNull($member->fresh()->activeMembershipAt($this->location), 'the membership was not opened');

        // 3) …and the member is servable, in the same session, with no navigation at all.
        $pos->call('chooseGenetic', $genetic->id)->set('weightInput', '3,50')->call('addLine');
        $this->assertCount(1, $pos->get('basket'), 'the member still cannot be dispensed to');
        $pos->assertDontSee('data-membership-fix', false);
    }

    /**
     * **The second report**: *"there's no link here either to add membership."* The door, same verdict, same
     * missing action — and the member can then be checked in.
     *
     * Fails against `main` for the same reason as the POS: `CheckInScreen` does not use `OpensMemberships`
     * there, so there is nothing on the screen to call.
     */
    public function test_a_member_with_no_membership_is_resolved_from_the_door_and_then_checked_in(): void
    {
        $this->operator();
        $member = $this->member();
        $tier = $this->tier();

        $door = Livewire::test(CheckInScreen::class)->call('selectMember', $member->id);
        $door->assertSee('data-membership-fix', false);

        $door->set('openTierId', $tier->id)->call('enrolAtThisSede');

        $this->assertNotNull($member->fresh()->activeMembershipAt($this->location), 'the membership was not opened');

        $door->call('checkIn');
        $this->assertSame(
            1,
            CheckIn::query()->withoutGlobalScopes()->where('member_id', $member->id)->count(),
            'the member could not be checked in after the fix',
        );
    }

    /** The lapsed case, on BOTH screens — 203's other route, through `RenewMembership`. */
    public function test_a_lapsed_membership_is_resolved_in_place_on_both_screens(): void
    {
        foreach ([DispensaryPos::class, CheckInScreen::class] as $screen) {
            $operator = $this->operator();
            $member = $this->member();
            $tier = $this->tier();

            $lapsed = Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $member->id,
                'location_id' => $this->location->id, 'tier_id' => $tier->id,
                'status' => MembershipStatus::LAPSED,
                'starts_at' => now()->subYears(2), 'expires_at' => now()->subMonth(),
                'fee_cents' => $tier->default_fee_cents->cents,
            ]);

            $this->assertNotNull($this->membershipRemedy($member, $operator)['action']);

            Livewire::test($screen)
                ->call('selectMember', $member->id)
                ->assertSee('data-membership-renew', false)
                ->call('renewMembership');

            // The SAME row, extended — not a second alta. 203's guarantee, still intact on every host.
            $this->assertSame(1, $member->memberships()->withoutGlobalScopes()->count(), $screen.': a second membership was created');
            $this->assertSame(MembershipStatus::ACTIVE, $lapsed->fresh()->status, $screen.': the membership was not renewed');
        }
    }

    /**
     * **Every consumer of `OpensMemberships` behaves identically** — iterated over the components that use
     * the trait, so a fourth counter screen cannot ship without it and cannot ship with it half-wired.
     *
     * 203's concern had exactly ONE consumer and its own docblock described a dead end that was live on two
     * other screens. This is the guard against that recurring.
     */
    public function test_every_consumer_of_the_concern_resolves_a_membership_the_same_way(): void
    {
        $hosts = [];

        foreach (glob(app_path('Livewire/Counter/*.php')) ?: [] as $file) {
            $class = 'App\\Livewire\\Counter\\'.basename($file, '.php');

            if (in_array(OpensMemberships::class, class_uses_recursive($class), true)) {
                $hosts[] = $class;
            }
        }

        $this->assertGreaterThanOrEqual(3, count($hosts), 'the concern lost a consumer: '.implode(', ', $hosts));

        foreach ($hosts as $host) {
            $this->operator();
            $member = $this->member();
            $tier = $this->tier();

            $component = Livewire::test($host);

            // Every host must be able to name its own subject — the one thing 211 had to add to the concern.
            $component->call('selectMember', $member->id)->assertSee('data-membership-fix', false);

            $component->set('openTierId', $tier->id)->call('enrolAtThisSede');

            $this->assertNotNull(
                $member->fresh()->activeMembershipAt($this->location),
                $host.' could not open a membership through the shared concern',
            );
        }
    }

    // --- Permission-awareness -----------------------------------------------------------------

    /**
     * An operator without `membership.enrol` gets an explanation and **no action** — and the wording does not
     * tell them to do something they cannot.
     *
     * The old sentence failed exactly here: it named *"su ficha"*, the admin panel, to a user who holds no
     * permission to act in it. Asserted as a property of the words, not only of the button.
     */
    public function test_an_operator_who_cannot_enrol_gets_no_action_and_no_instruction_they_cannot_follow(): void
    {
        $operator = $this->operator(mayEnrol: false);
        $member = $this->member();

        $remedy = $this->membershipRemedy($member, $operator);

        $this->assertNull($remedy['action'], 'an action was offered to somebody who may not take it');
        $this->assertNotNull($remedy['remedy'], 'they were left with no explanation at all');
        $this->assertSame(__('Pide a un responsable que le dé de alta en esta sede.'), $remedy['remedy']);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->assertDontSee('data-verdict-action="membership"', false);
    }

    /**
     * The verdict still BLOCKS either way — this branch changes what the operator can DO about it, never
     * whether the commit is refused.
     *
     * Asserted at the COMMIT, which is where the boundary is: `CommitDispensation` is the transactional
     * compliance boundary and a line on the basket is not a dispensation. Offering the remedy's action must
     * not have become a way past it.
     */
    public function test_the_verdict_still_blocks_whether_or_not_an_action_is_offered(): void
    {
        foreach ([true, false] as $mayEnrol) {
            $this->operator(mayEnrol: $mayEnrol);
            $member = $this->member();
            $genetic = $this->sellableGenetic();

            Livewire::test(DispensaryPos::class)
                ->call('selectMember', $member->id)
                ->call('chooseGenetic', $genetic->id)
                ->set('weightInput', '3,50')
                ->call('addLine')
                ->call('commitDispensation');

            $this->assertSame(
                0,
                Dispensation::query()->withoutGlobalScopes()->where('member_id', $member->id)->count(),
                'a member with no membership was dispensed to (mayEnrol: '.var_export($mayEnrol, true).')',
            );
        }
    }

    // --- The class, not the instance ---------------------------------------------------------

    /**
     * Every rule `VerdictRemedy::describe()` can return is covered — iterated over the enum, so a new rule
     * cannot arrive with a dead instruction.
     *
     * Before this branch `age` had no case at all: it fell through a `default` to the resolver's own sentence
     * with no remedy, which is indistinguishable from a rule that deliberately has none. That is precisely
     * what an eighth rule would have inherited.
     */
    public function test_every_eligibility_rule_declares_what_an_operator_can_do(): void
    {
        $operator = $this->operator();
        $member = $this->member();

        foreach (EligibilityRule::cases() as $case) {
            $remedy = VerdictRemedy::describe(
                ['rule' => $case->value, 'satisfied' => false, 'mode' => 'BLOCK', 'message' => 'x'],
                $member, $this->location, $operator,
            );

            $this->assertNotSame('', trim($remedy['detail']), "{$case->value} has no detail");
            $this->assertNotSame('', $case->label(), "{$case->value} has no label");

            if ($case->hasCounterAction()) {
                $this->assertNotNull($case->actionPermission(), "{$case->value} claims a counter action with no permission behind it");
                $this->assertNotNull($remedy['action'], "{$case->value} claims a counter action and offers none");
            } else {
                $this->assertNull($remedy['action'], "{$case->value} has no counter fix but offered an action");
            }
        }
    }

    /**
     * **The guard for the class**: no remedy names the admin panel or "their record" as the fix where a
     * counter-side action exists.
     *
     * That sentence is what survived 203 and put the same dead end on two more screens.
     */
    public function test_no_remedy_sends_the_operator_to_a_screen_the_counter_can_answer(): void
    {
        $operator = $this->operator();
        $member = $this->member();

        foreach (EligibilityRule::cases() as $case) {
            if (! $case->hasCounterAction()) {
                continue;
            }

            foreach ([$operator, $this->operator(mayEnrol: false)] as $actor) {
                $remedy = VerdictRemedy::describe(
                    ['rule' => $case->value, 'satisfied' => false, 'mode' => 'BLOCK', 'message' => 'x'],
                    $member, $this->location, $actor,
                );

                foreach ([__('ficha'), __('panel'), __('administración')] as $forbidden) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        $forbidden,
                        (string) $remedy['remedy'],
                        "{$case->value} sends the operator somewhere the counter can answer",
                    );
                }
            }
        }
    }

    /** The retired string is gone from both locales, not merely unused. */
    public function test_the_stale_instruction_is_retired_from_both_locales(): void
    {
        foreach (['en', 'es'] as $locale) {
            $keys = (array) json_decode((string) file_get_contents(base_path('lang/'.$locale.'.json')), true);

            $this->assertArrayNotHasKey(
                'Renueva su cuota desde su ficha para poder dispensarle.',
                $keys,
                "the sentence that caused this is still in lang/{$locale}.json",
            );
        }
    }

    // --- The other two screens ---------------------------------------------------------------

    /** 194: arriving with a socio does not add a second search box. */
    public function test_arriving_with_a_socio_adds_no_second_lookup(): void
    {
        $this->operator();
        $member = $this->member();

        $html = (string) $this->get(route('counter.members', ['socio' => $member->id]))->assertOk()->getContent();

        // With a socio held the lookup is REPLACED by their record, so the honest rule is "never more than
        // one", not "exactly one" — and the plain arrival still has its single box.
        $this->assertLessThanOrEqual(1, preg_match_all('/data-member-lookup(?![-\w])/', $html));
        $this->assertSame(1, preg_match_all('/data-member-lookup(?![-\w])/',
            (string) $this->get(route('counter.members'))->assertOk()->getContent()));
    }

    /** 177: no document artefact reaches the POS, whatever the verdict says. */
    public function test_the_pos_renders_no_document_artefact(): void
    {
        $this->operator();
        $member = $this->member();

        $html = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->html();

        foreach (['member-id-scans', 'document_scan', 'medical_cert'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }
}
