<?php

namespace Tests\Feature\Counter;

use App\Enums\DashboardAlert;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\ViewModels\Dashboard;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 207 — an alert that names a count and lands on a search box has not told you anything.
 *
 * The owner, on the hub's *Requiere atención* panel: *"the 2 alerts on the side don't actually tell you who
 * they are when you click on it. Need to make that function work."*
 *
 * The panel was right as far as it went — it rendered exactly what `Dashboard::alerts()` returns, and each
 * item was a link. But `alerts()` returns `['severity', 'key', 'count']` and nothing else, so the label was a
 * count and the destination was a **screen**: *"1 membresía vence pronto"* landed the operator on Socios, an
 * empty search box, with no way to find out WHICH membership without already knowing the answer. 205's own
 * comment above the panel says *"each item leads somewhere. An alert you cannot act on is decoration."* It
 * led somewhere. It did not lead to **the thing**.
 *
 * Naming the socio in the rail was considered and rejected on 177's reasoning — that screen is on display in
 * a room with the next socio standing behind the current one, which is why 177 put the consumption list
 * behind a deliberate tap and bound it to one member. The count stays on the hub; the names appear at the far
 * end, on the working screen, with the filter already applied.
 */
class AlertsLandOnTheSubjectTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    private function member(string $first, string $last): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => $first,
            'last_name' => $last,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
        ]);
    }

    /** A membership at this sede that falls inside the renewal window. */
    private function expiringMembership(Member $member): Membership
    {
        return Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subYear(),
            'expires_at' => now()->addDays(10),
        ]);
    }

    private function dashboard(): Dashboard
    {
        return new Dashboard($this->org->id, [$this->location->id], Period::today());
    }

    /** Follow an alert exactly as the rail does: read its href, then request it. */
    private function follow(string $key): string
    {
        $href = Livewire::test(CounterHome::class)->instance()->alertHref($key);
        $this->assertNotNull($href, "the {$key} alert leads nowhere");

        return (string) $this->get($href)->assertOk()->getContent();
    }

    // --- The reported bug -------------------------------------------------------------------

    /**
     * Following an alert arrives with the subject already resolved.
     *
     * **Fails against `main`**, where both of these landed on the bare Socios screen: the assertion is that
     * the member is ON the screen, not that the screen rendered.
     */
    public function test_following_the_expiring_membership_alert_names_the_member(): void
    {
        $this->operator();
        $member = $this->member('Ana', 'Ruiz');
        $this->expiringMembership($member);

        $this->assertSame(1, $this->dashboard()->expiringMemberships(), 'precondition: one expiring membership');

        $html = $this->follow(DashboardAlert::MEMBERSHIPS_EXPIRING->value);

        $this->assertStringContainsString('Ana Ruiz', $html, 'the operator was handed a search box, not the member');
        $this->assertStringContainsString('data-worklist-member="'.$member->id.'"', $html);
    }

    public function test_following_the_pending_application_alert_lands_on_the_application(): void
    {
        $this->operator();
        $application = MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'payload' => ['first_name' => 'Bruno', 'last_name' => 'Sáez'],
        ]);

        $this->assertSame(1, $this->dashboard()->pendingApplications(), 'precondition: one application awaiting review');

        $html = $this->follow(DashboardAlert::PENDING_APPLICATIONS->value);

        // The Alta panel is open on arrival, with the application in it — not a closed panel behind a tap.
        $this->assertStringContainsString('data-alta-pending', $html, 'the Alta panel did not open on arrival');
        $this->assertStringContainsString($application->id, $html, 'the application is not on the screen');
    }

    /**
     * TWO expiring memberships, both reachable from the ONE alert.
     *
     * A count of 1 passing by luck is the failure mode here: a destination that resolved only the first row,
     * or that happened to select a member because there was only one to select, would pass the test above.
     */
    public function test_every_subject_behind_a_count_is_reachable_from_the_one_alert(): void
    {
        $this->operator();
        $first = $this->member('Ana', 'Ruiz');
        $second = $this->member('Carlos', 'Vidal');
        $this->expiringMembership($first);
        $this->expiringMembership($second);

        $this->assertSame(2, $this->dashboard()->expiringMemberships());

        $html = $this->follow(DashboardAlert::MEMBERSHIPS_EXPIRING->value);

        foreach ([$first, $second] as $member) {
            $this->assertStringContainsString('data-worklist-member="'.$member->id.'"', $html, "{$member->first_name} is not reachable from the alert");
        }
    }

    /** And each row really does put that member on screen — the same path a typed search takes. */
    public function test_a_worklist_row_selects_that_member(): void
    {
        $this->operator();
        $member = $this->member('Ana', 'Ruiz');
        $this->expiringMembership($member);

        Livewire::test(MembershipCounter::class, ['alert' => DashboardAlert::MEMBERSHIPS_EXPIRING->value])
            ->call('selectMember', $member->id)
            ->assertSet('feeMemberId', $member->id)
            ->assertSee('Ana Ruiz');
    }

    // --- The rule that must hold for every key, not just the ones that exist today -----------

    /**
     * Every key `Dashboard::alerts()` can return either has a working destination or is deliberately
     * non-actionable — iterated over the enum, so a new alert cannot arrive with a silent `null`.
     *
     * `DashboardAlert` exists for exactly this: the vocabulary used to be three separate `match`es with three
     * separate `default`s, so an eighth alert could be added in `Dashboard::alerts()` and reach the hub as a
     * dead `<p>` that looks deliberate.
     */
    public function test_every_alert_key_declares_where_it_goes(): void
    {
        $this->operator();
        $hub = Livewire::test(CounterHome::class)->instance();

        foreach (DashboardAlert::cases() as $alert) {
            $route = $alert->counterRoute();

            if ($route !== null) {
                $this->assertTrue(Route::has($route), "{$alert->value} names a route that does not exist");
                $this->assertStringContainsString(
                    'alert='.$alert->value,
                    (string) $hub->alertHref($alert->value),
                    "{$alert->value} has a counter destination but arrives unfiltered",
                );
            } else {
                // Deliberately non-actionable at the counter — but never a dead end for somebody who CAN act:
                // an owner gets the matching panel resource, not the panel's front door.
                $this->assertNotSame(url('/'), $alert->panelUrl(), "{$alert->value} still points at the panel's front door");
                $this->assertSame($alert->panelUrl(), $hub->alertHref($alert->value));
            }

            $this->assertNotSame('', $alert->label(1), "{$alert->value} has no sentence");
        }
    }

    /**
     * No alert lands a user on a screen they cannot open — asserted for STAFF, who hold no panel access at
     * all. An alert that lands them on a 403 is worse than one that does not link.
     */
    public function test_no_alert_lands_staff_on_a_screen_they_cannot_open(): void
    {
        $this->operator(Role::STAFF);
        $hub = Livewire::test(CounterHome::class)->instance();

        foreach (DashboardAlert::cases() as $alert) {
            $href = $hub->alertHref($alert->value);

            if ($href === null) {
                continue;   // plainly non-actionable: the rail renders it as text, not a link
            }

            $this->get($href)->assertOk("{$alert->value} sends STAFF somewhere they cannot go");
        }

        // And a counter-only login — no panel access at all — is offered NOTHING that leaves the counter,
        // rather than a link into an admin panel that would 403 them.
        $counterOnly = User::factory()->create(['active' => false]);
        $counterOnly->assignRole(Role::STAFF->value);
        $counterOnly->locations()->sync([$this->location->id]);
        $this->actingAs($counterOnly);
        CounterOperator::set($counterOnly);
        $this->assertFalse($counterOnly->canAccessPanel(Filament::getPanel('admin')));

        $locked = Livewire::test(CounterHome::class)->instance();

        foreach (DashboardAlert::cases() as $alert) {
            $href = $locked->alertHref($alert->value);

            if ($alert->counterRoute() === null) {
                $this->assertNull($href, "{$alert->value} offers a locked-down login a way into the panel");
            } else {
                $this->assertStringStartsWith(url('/counter'), (string) $href);
            }
        }
    }

    // --- 177's boundary, extended to this panel ----------------------------------------------

    /**
     * The hub renders no member name, member number or document in any alert state, for any role.
     *
     * This is the denial that makes "the count stays on the hub" a rule rather than a description. Asserted
     * against the raw response body, in every alert state at once, because rendered-text assertions miss
     * exactly the leak that matters (185's precedent).
     */
    public function test_the_hub_never_names_a_member_in_any_alert_state(): void
    {
        // Deliberately unguessable names. This assertion searches a WHOLE PAGE for a string, and 'Ana' /
        // 'Bruno' are common enough that a random factory name elsewhere on it — an operator, an org, another
        // member — collides now and then and fails the test for the wrong reason. A leak is still a leak with
        // an odd name; a false positive is not.
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $this->operator($role);

            $member = $this->member('Zzqwertyx', 'Vbnmkjhg');
            $this->expiringMembership($member);
            MemberApplication::factory()->submitted()->create([
                'organisation_id' => $this->org->id,
                'location_id' => $this->location->id,
                'payload' => ['first_name' => 'Ppoiuytr', 'last_name' => 'Lkjhgfds'],
            ]);

            $html = (string) $this->get(route('counter.home'))->assertOk()->getContent();

            foreach (['Zzqwertyx', 'Vbnmkjhg', 'Ppoiuytr', 'Lkjhgfds', (string) $member->member_no, $member->id] as $leak) {
                $this->assertStringNotContainsString($leak, $html, "[{$role->value}] the hub leaked '{$leak}'");
            }
        }
    }

    /** Nothing pending: the designed empty state, not a blank box. */
    public function test_the_empty_state_still_renders_when_nothing_is_pending(): void
    {
        $this->operator();

        $html = (string) $this->get(route('counter.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-alerts-empty', $html);
        $this->assertStringContainsString(__('Nada pendiente. Todo en orden.'), $html);
    }

    /** An arrival with no alert is the ordinary screen — and neither arrival grows a second search box (194). */
    public function test_socios_without_an_alert_is_unchanged_and_neither_arrival_adds_a_second_lookup(): void
    {
        $this->operator();
        $member = $this->member('Ana', 'Ruiz');
        $this->expiringMembership($member);

        $filtered = $this->follow(DashboardAlert::MEMBERSHIPS_EXPIRING->value);
        $this->assertSame(1, preg_match_all('/data-member-lookup(?![-\w])/', $filtered), 'the filtered arrival added a second lookup');

        $html = (string) $this->get(route('counter.members'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-alert-worklist', $html);
        $this->assertSame(1, preg_match_all('/data-member-lookup(?![-\w])/', $html), 'a filtered arrival must not add a second lookup');
    }

    // --- The two counts that had drifted from their own subjects ------------------------------

    /**
     * The nightly sweep used to empty this alert.
     *
     * `SweepMembershipExpiry` flips memberships inside the window to `EXPIRING_SOON`, and
     * `Dashboard::expiringMemberships()` counted `status = ACTIVE` — so the morning after the sweep ran, the
     * alert reported nothing. Both now read `Membership::expiringSoon()`.
     */
    public function test_a_membership_the_sweep_has_already_flagged_still_counts_and_still_lands(): void
    {
        $this->operator();
        $member = $this->member('Ana', 'Ruiz');
        $this->expiringMembership($member)->update(['status' => MembershipStatus::EXPIRING_SOON]);

        $this->assertSame(1, $this->dashboard()->expiringMemberships(), 'the sweep emptied the alert');
        $this->assertStringContainsString('Ana Ruiz', $this->follow(DashboardAlert::MEMBERSHIPS_EXPIRING->value));
    }

    /**
     * An invitation nobody has filled in is not a pending application.
     *
     * It counted as one, while the Alta panel has always listed only SUBMITTED forms — so the hub could say
     * *"1 solicitud pendiente"* and land the operator on an empty panel. One scope now
     * (`MemberApplication::awaitingReview()`), both callers.
     */
    public function test_an_unsubmitted_invitation_is_not_counted_as_pending(): void
    {
        $this->operator();
        MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'submitted_at' => null,
        ]);

        $this->assertSame(0, $this->dashboard()->pendingApplications(), 'an unfilled invitation is not work');
    }
}
