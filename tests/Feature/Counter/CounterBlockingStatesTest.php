<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterBlocker;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 175 — four blockers, four styles, no order.
 *
 * The dispensary told the operator four things at once, in four visual languages, with nothing saying which
 * to fix first: an amber operator strip, a red `bg-error` till card with a dark-red "Ir a la caja", a grey
 * member empty state, and grey helper text under the commit button restating the member blocker.
 *
 * They are not equal. Without a sede nothing works; without an operator nothing may be WRITTEN; without an
 * open till nothing may be dispensed; without a member there is nothing to dispense. A strict chain,
 * presented as a pile. `CounterBlocker` resolves it to ONE, and `x-counter.blocking-state` draws it.
 *
 * The operator step is in the chain (so till and member cannot jump it) but is never drawn in-page — prompt
 * 173's full-screen surface owns it, and two things drawing one state is what 173 spent a branch deleting.
 *
 * PRESENTATION ONLY. The last group here is the one that matters most: every server-side refusal still
 * refuses when the screen is bypassed, so this branch cannot have turned four gates into four pictures.
 */
class CounterBlockingStatesTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    /** Signed in, at a sede, PIN-identified unless told otherwise. */
    private function operator(bool $withPin = true, Role $role = Role::MANAGER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        if ($withPin) {
            CounterOperator::set($user);
        }

        return $user;
    }

    private function openTill(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function blockerCount(string $html): int
    {
        return substr_count($html, 'data-counter-blocker');
    }

    private function blockerKind(string $html): ?string
    {
        preg_match('/data-blocker="([a-z-]+)"/', $html, $m);

        return $m[1] ?? null;
    }

    // --- One at a time -----------------------------------------------------------

    /**
     * The regression test for the pile. With a sede and an operator but nothing else, main drew THREE things
     * on the dispensary at once (the red till card, the grey member panel and the helper text under the
     * commit button). Exactly one blocking state renders now, and it is the first unmet link in the chain.
     */
    public function test_the_dispensary_renders_exactly_one_blocking_state_not_a_pile(): void
    {
        $this->operator();
        $html = Livewire::test(DispensaryPos::class)->html();

        $this->assertSame(1, $this->blockerCount($html));
        $this->assertSame('till', $this->blockerKind($html));

        // The two member statements main showed alongside it are gone, not merely restyled.
        $this->assertStringNotContainsString(__('Identifica a un socio para poder registrar.'), $html);
    }

    /** Every screen wired in this branch shows at most one, in every combination of missing preconditions. */
    public function test_no_screen_ever_renders_more_than_one_blocking_state(): void
    {
        $screens = [DispensaryPos::class, BarPos::class, TillSession::class, CheckInScreen::class, MembershipCounter::class];

        $assertAtMostOne = function (bool $withTill) use ($screens): void {
            foreach ([true, false] as $withPin) {
                $this->operator(withPin: $withPin);

                foreach ($screens as $screen) {
                    $html = Livewire::test($screen)->html();
                    $this->assertLessThanOrEqual(
                        1,
                        $this->blockerCount($html),
                        $screen.' drew more than one blocking state (pin: '.var_export($withPin, true).', till: '.var_export($withTill, true).')'
                    );
                }

                CounterOperator::clear();
            }
        };

        $assertAtMostOne(false); // the till step unmet
        $this->openTill();       // one terminal, opened once
        $assertAtMostOne(true);  // and met
    }

    // --- The order ---------------------------------------------------------------

    /** sede → operator → till → member, resolved to the FIRST unmet link and no other. */
    public function test_the_chain_resolves_in_dependency_order(): void
    {
        $all = [CounterBlocker::SEDE => false, CounterBlocker::OPERATOR => false, CounterBlocker::TILL => false, CounterBlocker::MEMBER => false];

        $this->assertSame(CounterBlocker::SEDE, CounterBlocker::first($all));
        $this->assertSame(CounterBlocker::OPERATOR, CounterBlocker::first([...$all, CounterBlocker::SEDE => true]));
        $this->assertSame(CounterBlocker::TILL, CounterBlocker::first([...$all, CounterBlocker::SEDE => true, CounterBlocker::OPERATOR => true]));
        $this->assertSame(CounterBlocker::MEMBER, CounterBlocker::first([...$all, CounterBlocker::SEDE => true, CounterBlocker::OPERATOR => true, CounterBlocker::TILL => true]));
        $this->assertNull(CounterBlocker::first(array_map(fn (): bool => true, $all)));
    }

    /** A precondition that does not apply to a screen is ABSENT from the chain, not false. */
    public function test_a_precondition_absent_from_a_screen_is_skipped(): void
    {
        // Recepción: sede + operator only. No till, no member — and so never blocked on them.
        $this->assertNull(CounterBlocker::first([CounterBlocker::SEDE => true, CounterBlocker::OPERATOR => true]));
    }

    /** The dispensary walks the chain as each link is met: till first, then member, then the work. */
    public function test_fixing_one_link_reveals_the_next_on_the_dispensary(): void
    {
        $this->operator();
        $this->assertSame('till', $this->blockerKind(Livewire::test(DispensaryPos::class)->html()));

        $this->openTill();
        $this->assertSame('member', $this->blockerKind(Livewire::test(DispensaryPos::class)->html()));

        $html = Livewire::test(DispensaryPos::class)->call('selectMember', $this->eligibleMember()->id)->html();
        $this->assertSame(0, $this->blockerCount($html));
        $this->assertStringContainsString('lg:grid-cols-[minmax(0,1fr)_22rem]', $html); // the work
    }

    /** No sede outranks everything below it — the till card never appears over the top of it. */
    public function test_the_sede_step_outranks_the_till_step(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $this->actingAs($user); // no sede assigned at all

        $html = Livewire::test(DispensaryPos::class)->html();

        $this->assertSame(1, $this->blockerCount($html));
        $this->assertSame('sede', $this->blockerKind($html));
        $this->assertStringNotContainsString(__('No hay caja abierta'), $html);
    }

    // --- The operator step is reported, never drawn ------------------------------

    /** `rendersInPage` is the guard that stops a second operator UI existing beside 173's surface. */
    public function test_the_operator_step_is_reported_but_not_rendered(): void
    {
        $this->assertTrue(CounterBlocker::rendersInPage(CounterBlocker::SEDE));
        $this->assertTrue(CounterBlocker::rendersInPage(CounterBlocker::TILL));
        $this->assertTrue(CounterBlocker::rendersInPage(CounterBlocker::MEMBER));

        $this->assertFalse(CounterBlocker::rendersInPage(CounterBlocker::OPERATOR));
        $this->assertFalse(CounterBlocker::rendersInPage(null));
    }

    /** With no PIN, no screen draws an operator blocking state — 173's surface is the only one. */
    public function test_no_screen_draws_an_operator_blocking_state(): void
    {
        $this->operator(withPin: false);
        $this->openTill();

        foreach ([DispensaryPos::class, BarPos::class, TillSession::class, CheckInScreen::class, MembershipCounter::class] as $screen) {
            $html = Livewire::test($screen)->html();

            $this->assertStringNotContainsString('data-blocker="operator"', $html, $screen);
            $this->assertStringContainsString('data-surface-mode="unidentified"', $html, $screen); // 173 has it
        }
    }

    // --- One action, and it resolves what it names -------------------------------

    /** The till state offers exactly one action, and it goes to the Caja screen. */
    public function test_the_till_blocking_state_has_one_action_that_resolves_it(): void
    {
        $this->operator();
        $html = Livewire::test(DispensaryPos::class)->html();

        $this->assertSame(1, substr_count($html, 'data-blocker-action'));
        $this->assertStringContainsString(route('counter.till'), $html);
        $this->assertStringContainsString(__('Ir a la caja'), $html);
    }

    /**
     * The member state is the one whose fix lives ON the blocked screen, so its single action is the lookup
     * itself rather than a link. A blocking state that removed the only means of resolving it would be a
     * dead end — this asserts it is not.
     */
    public function test_the_member_blocking_state_carries_the_lookup_that_resolves_it(): void
    {
        $this->operator();
        $this->openTill();
        $member = $this->eligibleMember();

        $component = Livewire::test(DispensaryPos::class);
        $html = $component->html();

        $this->assertSame('member', $this->blockerKind($html));
        $this->assertSame(1, substr_count($html, 'data-blocker-action'));
        $this->assertStringContainsString('id="scan"', $html);          // the scan field
        $this->assertStringContainsString('id="member-search"', $html); // and the name / nº lookup

        // And it actually resolves: searching from the blocking state surfaces the socio and clears it.
        $component->set('search', $member->last_name)->assertSee($member->member_no)
            ->call('selectMember', $member->id);

        $this->assertSame(0, $this->blockerCount($component->html()));
    }

    // --- Colour has one meaning --------------------------------------------------

    /** Blocked is neutral; red is DESTRUCTIVE. `Ir a la caja` is navigation, so it is the brand button. */
    public function test_no_blocking_state_uses_the_destructive_colour(): void
    {
        $this->operator();

        foreach ([DispensaryPos::class, BarPos::class] as $screen) {
            $html = Livewire::test($screen)->html();

            $this->assertSame('till', $this->blockerKind($html), $screen);
            $this->assertStringNotContainsString('bg-error px-4', $html, $screen); // the old dark-red button
            $this->assertMatchesRegularExpression(
                '/data-blocker-action\s+class="[^"]*bg-brand/',
                $html,
                $screen.': Ir a la caja must be the brand button, not the destructive one'
            );
        }
    }

    /** Every control in a blocking state clears the counter's 44x44 floor. */
    public function test_blocking_state_controls_meet_the_touch_floor(): void
    {
        $this->operator();
        $html = Livewire::test(DispensaryPos::class)->html();

        $this->assertMatchesRegularExpression('/data-blocker-action\s+class="[^"]*min-h-\[2\.75rem\]/', $html);
    }

    // --- The gates are still gates ----------------------------------------------
    //
    // The whole risk of this branch: it could have replaced four real refusals with four pictures of
    // refusals. Each of these bypasses the screen entirely and calls the write directly.

    public function test_a_commit_without_a_sede_is_still_refused(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $this->actingAs($user); // no sede

        Livewire::test(DispensaryPos::class)->call('commit')->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->count());
    }

    public function test_a_commit_without_a_pin_identified_operator_is_still_refused(): void
    {
        $this->operator(withPin: false);
        $this->openTill();
        $member = $this->eligibleMember();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('commit')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->count());
    }

    public function test_a_commit_without_an_open_till_is_still_refused(): void
    {
        $this->operator();
        $member = $this->eligibleMember();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('commit')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->count());
    }

    public function test_a_commit_without_a_member_is_still_refused(): void
    {
        $this->operator();
        $this->openTill();

        Livewire::test(DispensaryPos::class)
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Identifica a un socio antes de registrar una dispensación.'));

        $this->assertSame(0, Dispensation::query()->count());
    }

    /** A refusal must still reach the operator when a blocking state is what is on screen. */
    public function test_a_refusal_is_visible_from_inside_a_blocking_state(): void
    {
        $this->operator();
        $this->openTill(); // → the member blocking state is what renders

        Livewire::test(DispensaryPos::class)
            ->call('commit')
            ->assertSee(__('Identifica a un socio antes de registrar una dispensación.'));
    }
}
