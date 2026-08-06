<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterScreens;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Prompt 172 — the panel carried FOUR separate links into the counter, in four different navigation
 * groups, presenting one application as four tools reached from four places. The fifth destination
 * (counter.members) never had a link at all, which is what made it drift rather than policy.
 *
 * The counter is one application with its own permission-filtered tab strip; once inside, that strip is
 * the navigation. So: one front door, gated and aimed by the SAME list the tab strip reads.
 */
class OneCounterLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        app(ActiveScope::class)->setLocation(Location::factory()->create(['organisation_id' => $org->id])->id);
    }

    private function actor(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<NavigationItem> */
    private function counterLinks(): array
    {
        return array_values(array_filter(
            Filament::getPanel('admin')->getNavigationItems(),
            fn ($item): bool => str_contains((string) $item->getUrl(), '/counter') && $item->isVisible(),
        ));
    }

    // --- Exactly one, and only when it leads somewhere -------------------------------------------------

    public function test_a_user_who_can_reach_the_counter_sees_exactly_one_link(): void
    {
        $this->actor(Role::OWNER);

        // Asserting the COUNT, so a fifth item added later without thought fails the suite.
        $this->assertCount(1, $this->counterLinks(),
            'The counter is one application and must have exactly one sidebar link.');
    }

    public function test_a_user_with_no_counter_permission_sees_no_link_at_all(): void
    {
        // A role with no counter screen at all — the link must not render pointing at a 403.
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(CounterScreens::reachableByAny($user));
        $this->assertNull(CounterScreens::landingRouteFor($user));
        $this->assertSame([], $this->counterLinks());
    }

    // --- The trap: it must land somewhere the user is allowed to be ------------------------------------

    public function test_it_lands_on_reception_for_a_user_who_can_be_there(): void
    {
        $owner = $this->actor(Role::OWNER);

        $this->assertSame('counter.checkin', CounterScreens::landingRouteFor($owner));
    }

    public function test_a_till_only_operator_lands_on_the_till_not_on_a_403(): void
    {
        // THE trap this branch had to close. A user with till.open and not checkin.manage previously had a
        // direct link to the till; sending them to Recepción instead would 403 on arrival.
        $user = User::factory()->create();
        $user->givePermissionTo('till.open');
        $this->actingAs($user);

        $this->assertFalse($user->can('checkin.manage'));
        $this->assertSame('counter.till', CounterScreens::landingRouteFor($user));

        // And the link they get actually opens.
        $this->get(route('counter.till'))->assertSuccessful();
    }

    public function test_the_landing_screen_is_always_one_the_user_may_open(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $user = $this->actor($role);
            $route = CounterScreens::landingRouteFor($user);

            if ($route === null) {
                continue;
            }

            $reachable = array_column(CounterScreens::reachableFor($user), 'route');
            $this->assertContains($route, $reachable, "{$role->value} would land on a screen they cannot open.");
        }
    }

    // --- The rule is not duplicated ---------------------------------------------------------------------

    public function test_the_tab_strip_and_the_sidebar_read_the_same_list(): void
    {
        // The tab strip declared this list inline; the sidebar needed the same answer. A second copy is
        // exactly the drift that produced two PIN pads (prompt 173), so the list was extracted and both
        // consume it. If the strip ever declares its own again, this fails.
        $strip = (string) file_get_contents(base_path('resources/views/components/counter/top-bar.blade.php'));

        $this->assertStringContainsString('CounterScreens::reachableFor($user)', $strip);
        $this->assertStringNotContainsString("'route' => 'counter.checkin'", $strip,
            'The tab strip has re-declared its own screen list — there must be exactly one.');
    }

    public function test_the_tab_strip_still_offers_the_same_five_destinations(): void
    {
        $this->actor(Role::OWNER);

        $this->assertSame(
            ['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'],
            array_column(CounterScreens::forUser(Auth::user()), 'route'),
        );
    }

    public function test_each_screens_gate_is_unchanged(): void
    {
        // Mirrors of each component's own mount() gate — the extraction must not have relaxed one.
        $staff = $this->actor(Role::STAFF);
        $granted = array_column(CounterScreens::forUser($staff), 'granted', 'route');

        $this->assertSame($staff->can('checkin.manage'), $granted['counter.checkin']);
        $this->assertSame($staff->can('membership.fee.collect'), $granted['counter.members']);
        $this->assertSame($staff->can('pos.use'), $granted['counter.pos']);
        $this->assertSame($staff->can('till.open') || $staff->can('till.close'), $granted['counter.till']);
    }

    // --- Nothing left behind ------------------------------------------------------------------------------

    public function test_no_navigation_group_renders_empty(): void
    {
        // Removing four items could have left a group whose only member was one of them.
        $this->actor(Role::OWNER);
        $panel = Filament::getPanel('admin');

        $used = [];
        foreach ($panel->getNavigationItems() as $item) {
            if ($item->isVisible() && filled($item->getGroup())) {
                $used[(string) $item->getGroup()] = true;
            }
        }
        foreach ($panel->getResources() as $resource) {
            if (filled($resource::getNavigationGroup())) {
                $used[(string) $resource::getNavigationGroup()] = true;
            }
        }
        foreach ($panel->getPages() as $page) {
            if (method_exists($page, 'getNavigationGroup') && filled($page::getNavigationGroup())) {
                $used[(string) $page::getNavigationGroup()] = true;
            }
        }

        foreach ($panel->getNavigationGroups() as $group) {
            $label = (string) (is_string($group) ? $group : $group->getLabel());
            $this->assertArrayHasKey($label, $used, "Navigation group [{$label}] renders with no items in it.");
        }
    }
}
