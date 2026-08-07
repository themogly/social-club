<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Dashboard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 205 — the hub is the menu, the bar is the terminal strip.
 *
 * The owner, on `/counter`: *"this dashboard doesn't work, you go to it and can't get back to it. Also it's
 * just duplicate data."* Both checked out. **The route home was a logo** — a 44×44 brand-blue square with one
 * letter and an `aria-label`, with the words beside it in a separate, unclickable `div`. And after prompt 189
 * the five destinations were in the bar AND on the hub, and so were the sede, the working operator, Panel and
 * Log out: 189's prompt said the non-transaction operations belong on the home screen, they were added there,
 * and they were never removed from the bar.
 *
 * The trade the owner accepted: **two taps to switch screens, in exchange for one place per control.** That
 * trade is only payable because the basket now survives navigation — which is why the persistence assertions
 * below are part of this branch and not a later one.
 */
class CounterHubTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'capacity' => 20]);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function sellableGenetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
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

    /** Every counter screen, so a rule proved on one is proved on all of them. */
    private function everyScreen(): array
    {
        return ['counter.home', 'counter.checkin', 'counter.members', 'counter.pos', 'counter.bar', 'counter.till'];
    }

    // --- "You can't get back to it" -------------------------------------------------

    /**
     * The Home link is present, labelled and reachable on every counter screen.
     *
     * The reported bug is that nobody could SEE it, so the assertion is on the accessible name being a WORD
     * in the link's own text — an `aria-label` on an icon is what it had, and that is what failed.
     */
    public function test_the_home_link_is_labelled_and_on_every_counter_screen(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        foreach ($this->everyScreen() as $route) {
            $html = (string) $this->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('data-counter-home-link', $html, "{$route} has no way home");

            // The visible text of the link, not an aria-label on an icon.
            preg_match('/data-counter-home-link.*?<\/a>/s', $html, $link);
            $this->assertNotEmpty($link, "{$route}: could not read the home link");
            $this->assertStringContainsString(__('Inicio'), $link[0], "{$route}: the home link has no visible word");
        }

        $this->get(route('counter.home'))->assertOk();
    }

    // --- "Just duplicate data" -------------------------------------------------------

    /**
     * No control appears in two places. Fails against `main`, where all five did.
     *
     * Counted per hook across the bar and the hub together, because "it renders" was never the question —
     * every one of these rendered. The question is how many times.
     */
    public function test_no_terminal_control_renders_in_two_places(): void
    {
        $user = $this->operator();
        Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte'])
            ->users()->attach($user->id);

        $html = (string) $this->get(route('counter.home'))->assertOk()->getContent();
        $stripped = (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);

        foreach ([
            'data-counter-sede-region' => 'the sede',
            'data-operator-name-chip' => 'the operator chip',
            'data-counter-lock' => 'Lock',
            'data-counter-admin-link' => 'Panel',
            'data-counter-logout' => 'Log out',
            'data-counter-home-link' => 'the Home link',
        ] as $hook => $what) {
            $this->assertSame(1, substr_count($stripped, $hook), "{$what} renders more than once");
        }
    }

    /** The destination list renders on the hub and nowhere else — the tab strip is gone. */
    public function test_the_destinations_render_only_on_the_hub(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        foreach (['counter.checkin', 'counter.pos', 'counter.bar', 'counter.till'] as $route) {
            $html = (string) $this->get(route($route))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-counter-screen=', $html, "{$route} still carries a tab strip");
        }

        $hub = (string) $this->get(route('counter.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-counter-home-tile="counter.pos"', $hub);
    }

    // --- Tiles are the permission list, and nothing else ------------------------------

    public function test_the_hub_renders_a_tile_for_every_reachable_screen_and_none_it_may_not_open(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('till.open');   // ONE counter permission, deliberately
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        $html = Livewire::test(CounterHome::class)->html();

        $this->assertStringContainsString('data-counter-home-tile="counter.till"', $html);
        foreach (['counter.checkin', 'counter.members', 'counter.pos', 'counter.bar'] as $forbidden) {
            $this->assertStringNotContainsString('data-counter-home-tile="'.$forbidden.'"', $html, "a tile to a 403: {$forbidden}");
        }

        // Their one tile is the hero — the hub degrades by role rather than leaving a hole where it goes.
        $this->assertStringContainsString('data-counter-home-hero', $html);
        $this->get(route('counter.till'))->assertOk();
    }

    /**
     * ONE tile is the hero, and it is whichever destination the club configured.
     *
     * **205 asserted `counter.checkin` here**, because the hero was `tiles()[0]` and Recepción is first in
     * `CounterScreens`. Prompt 208 separated those: the hero is the `counter_hero` Setting (the owner's call
     * is the dispensary), and this order means only the grid's reading order and `landingRouteFor()`'s
     * fallback again. What 205 was actually protecting — that exactly one tile is promoted and it is one the
     * operator may open — is what is asserted now; which route wins lives in `CounterHeroTileTest`.
     */
    public function test_exactly_one_tile_is_the_hero_and_it_is_the_configured_one(): void
    {
        $this->operator();

        $html = Livewire::test(CounterHome::class)->html();

        preg_match('/data-counter-home-tile="([^"]+)"[^>]*data-counter-home-hero/s', $html, $hero);
        $this->assertSame(
            Settings::get('counter_hero', 'counter.pos'),
            $hero[1] ?? null,
            'the hero is not the configured destination',
        );
        $this->assertSame(1, substr_count($html, 'data-counter-home-hero'));
    }

    // --- Every figure comes from Dashboard --------------------------------------------

    /**
     * Each panel equals its `Dashboard` method, asserted by calling the resolver — never against a literal.
     *
     * 177's rule on a new screen: if a number here ever disagrees, THIS SCREEN is wrong and the resolver is
     * right. A test written against a hard-coded 3 would pass while the hub grew its own query.
     */
    public function test_every_hub_figure_equals_its_dashboard_method(): void
    {
        $user = $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->member();
        CheckIn::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id, 'checked_in_at' => now(), 'checked_out_at' => null,
        ]);

        Livewire::test(BarPos::class)
            ->call('addArticle', Article::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'price_cents' => 250, 'stock' => 10, 'active' => true,
            ])->id)
            ->set('cashTendered', '2,50')
            ->call('commitOrder');

        $dashboard = Dashboard::for($user, Period::today());
        $panels = Livewire::test(CounterHome::class)->instance()->panels();

        $this->assertSame($dashboard->insideNow(), $panels['inside']);
        $this->assertSame($dashboard->checkInsToday(), $panels['check_ins']);
        $this->assertSame($dashboard->transactionCount(), $panels['transactions']);
        $this->assertSame(Money::fromCents($dashboard->contributionsCents())->formatted(), $panels['taken']);
        $this->assertSame($dashboard->alerts(), $panels['alerts']);

        // …and they are on screen, not merely computed.
        $html = Livewire::test(CounterHome::class)->html();
        $this->assertStringContainsString('data-figure="inside"', $html);
        $this->assertStringContainsString('data-figure="transactions"', $html);
    }

    /** alerts() drives the attention panel: make one fire, it appears; clear it, it goes. */
    public function test_the_attention_panel_is_driven_by_dashboard_alerts(): void
    {
        $this->operator();

        $clear = Livewire::test(CounterHome::class)->html();
        $this->assertStringContainsString('data-alerts-empty', $clear, 'precondition: nothing pending');
        $this->assertStringNotContainsString('data-alert="unreconciled_till"', $clear);

        // An open till from yesterday is exactly what hasUnreconciledTill() reports.
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        DB::table('till_sessions')->where('id', $till->id)->update(['opened_at' => now()->subDays(2)]);

        $firing = Livewire::test(CounterHome::class)->html();
        $this->assertStringContainsString('data-alert="unreconciled_till"', $firing);
        // It leads somewhere: an alert you cannot act on is decoration.
        $this->assertStringContainsString(route('counter.till'), $firing);

        DB::table('till_sessions')->where('id', $till->id)->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->assertStringNotContainsString('data-alert="unreconciled_till"', Livewire::test(CounterHome::class)->html());
    }

    // --- The takings decision, enforced ------------------------------------------------

    /**
     * STAFF cannot see the day's takings, and the rest of the hub still renders for them.
     *
     * A security judgement, not a layout one: this screen is the landing page AND the only route between
     * screens, so it is on display all shift in a room with members and visitors in it, in a cash business
     * with a panic button because robbery is a real risk (prompt 121). It follows Dashboard's EXISTING
     * finance rule rather than inventing a second one — and the panel is ABSENT, not blurred or zeroed.
     */
    public function test_a_staff_operator_cannot_see_the_days_takings_and_still_gets_a_hub(): void
    {
        $this->operator(Role::STAFF);

        $component = Livewire::test(CounterHome::class);
        $html = $component->html();

        $this->assertFalse($component->instance()->canSeeTakings());
        $this->assertNull($component->instance()->panels()['taken']);
        $this->assertStringNotContainsString('data-figure="taken"', $html, 'STAFF can read the day\'s cash');
        $this->assertStringNotContainsString('data-figure-row="taken"', $html);

        // …and the rest of it is intact, not a broken box.
        $this->assertStringContainsString('data-figure="inside"', $html);
        $this->assertStringContainsString('data-panel="alerts"', $html);
        $this->assertStringContainsString('data-counter-home-tile="counter.pos"', $html);
    }

    public function test_an_owner_does_see_the_days_takings(): void
    {
        $this->operator(Role::OWNER);

        $this->assertStringContainsString('data-figure="taken"', Livewire::test(CounterHome::class)->html());
    }

    // --- The price this branch had to pay -----------------------------------------------

    /**
     * A basket in progress survives a trip to the hub and back. Fails against `main`.
     *
     * The persistence that was there wrote `localStorage` on every change and **nothing in the entire
     * `resources/` tree ever called `getItem`** — write-only for two years, reading like a safety net and
     * catching nothing. With the destinations out of the bar, every screen change goes through the hub, so
     * this is what makes hub-only navigation usable at all.
     */
    public function test_a_dispensary_basket_survives_navigating_to_the_hub_and_back(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $genetic = $this->sellableGenetic();

        $before = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member()->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,50')
            ->call('addLine');

        $this->assertCount(1, $before->get('basket'), 'precondition: a line on the basket');

        Livewire::test(CounterHome::class)->assertOk();          // the trip home

        $this->assertCount(1, Livewire::test(DispensaryPos::class)->get('basket'), 'the basket did not survive');
    }

    public function test_a_bar_basket_survives_navigating_to_the_hub_and_back(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ]);

        Livewire::test(BarPos::class)->call('addArticle', $article->id);
        Livewire::test(CounterHome::class)->assertOk();

        $this->assertCount(1, Livewire::test(BarPos::class)->get('basket'));
    }

    /** A committed basket does not come back — the stash follows the basket, including when it empties. */
    public function test_a_committed_basket_is_not_restored(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ]);

        Livewire::test(BarPos::class)->call('addArticle', $article->id)->set('cashTendered', '5,00')->call('commitOrder');

        $this->assertSame([], Livewire::test(BarPos::class)->get('basket'));
    }

    /**
     * A basket never reappears under a DIFFERENT operator.
     *
     * Prompt 198 is explicit that work survives a lock, so the stash deliberately outlives one — but the key
     * is (screen · sede · operator), so the next person to identify at this terminal starts empty. Same
     * reasoning as prompt 202's settled outcome, one step more serious, because this one can be committed.
     */
    public function test_a_basket_never_reappears_under_a_different_operator(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        Livewire::test(BarPos::class)->call('addArticle', Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ])->id);

        $this->assertCount(1, Livewire::test(BarPos::class)->get('basket'));

        $next = User::factory()->create();
        $next->assignRole(Role::OWNER->value);
        $next->locations()->sync([$this->location->id]);
        CounterOperator::set($next);

        $this->assertSame([], Livewire::test(BarPos::class)->get('basket'), 'somebody else\'s basket appeared');
    }

    // --- 120's rule, and the query budget -------------------------------------------------

    /**
     * The hub does not poll, so its own rendering can never reset the idle timer.
     *
     * Prompt 120's recorded rule is that only genuine pointer, key or touch input resets it — *"Livewire
     * polling and re-renders never do"* — and a refreshing hub is exactly the thing that would quietly break
     * that. It is fresh because real navigation re-renders it, which is the only kind that counts.
     */
    public function test_the_hub_does_not_poll(): void
    {
        $this->operator();

        $html = Livewire::test(CounterHome::class)->html();

        $this->assertStringNotContainsString('wire:poll', $html, 'a polling hub would reset the idle lock');
        $this->assertStringNotContainsString('setInterval', $html);
    }

    /**
     * The hub's query count is BOUNDED and recorded — it is now the most-hit page in the product.
     *
     * The dashboard's own entry records ~85 bounded queries for a screen opened a few times a day. That is
     * not the budget for one that renders on every navigation, all shift, on a tablet.
     */
    public function test_the_hub_query_count_is_bounded(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        foreach (range(1, 5) as $i) {
            CheckIn::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $this->member()->id,
                'location_id' => $this->location->id, 'checked_in_at' => now(), 'checked_out_at' => null,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CounterHome::class)->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Measured at 32 on this branch. The bound is deliberately close to it: the dashboard's own entry
        // records ~85 bounded queries for a screen opened a few times a day, and this one renders on every
        // navigation, all shift. Room to breathe, not room to drift.
        $this->assertLessThanOrEqual(40, $count, "the hub ran {$count} queries");
    }

    /**
     * …and it does not grow per ROW — the N+1 half of the same question.
     *
     * The rows added are check-ins for the SAME member, deliberately. Adding twelve new MEMBERS would also
     * change which `alerts()` branches have anything to report, and a count that moved for that reason would
     * be a data-dependent number rather than an N+1 — the experiment has to vary one thing.
     */
    public function test_the_hub_query_count_does_not_grow_per_row(): void
    {
        $this->operator();
        $member = $this->member();

        // Render once and throw it away: the FIRST render of the process also pays for cold caches (settings,
        // the permission table), so measuring it against a later one compares two different things and reads
        // as a saving where there is none.
        Livewire::test(CounterHome::class)->html();

        // And flush between the two, not just disable: `disableQueryLog()` stops RECORDING but keeps what it
        // has, so the second measurement would otherwise be "first render + second render" and read as an
        // N+1 that is not there. (It did, on the first draft of this test — twice, in both directions.)
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CounterHome::class)->html();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 20) as $i) {
            CheckIn::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $member->id,
                'location_id' => $this->location->id, 'checked_in_at' => now(), 'checked_out_at' => null,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CounterHome::class)->html();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large, "N+1: {$small} queries became {$large} with 20 more rows");
    }

    // --- What 205 must not have broken ----------------------------------------------------

    /** Lockdown: absent without the permission, present and confirming with it — one tap, in the bar. */
    public function test_the_panic_trigger_stays_gated_confirming_and_one_tap(): void
    {
        $staff = $this->operator(Role::STAFF);
        $this->assertTrue($staff->can('lockdown.initiate'));

        $html = (string) $this->get(route('counter.pos'))->getContent();
        $this->assertStringContainsString('data-counter-panic', $html, 'staff must be able to reach it');
        $this->assertStringContainsString(__('¿Activar el bloqueo de seguridad? Cerrará el club entero.'), $html);
        // ONE tap: it is a control in the bar, not an item inside a menu that must be opened first.
        $this->assertStringNotContainsString('data-counter-overflow-trigger', $html);

        $without = User::factory()->create();
        $without->givePermissionTo('pos.use');
        $without->locations()->sync([$this->location->id]);
        CounterOperator::set($without);
        $this->actingAs($without);

        $this->assertStringNotContainsString(
            'data-counter-panic',
            (string) $this->get(route('counter.pos'))->getContent(),
            'the panic trigger must be ABSENT from the DOM without the permission',
        );
    }

    /** The blocking chain still applies to the hub: no sede blocks it exactly as it blocks the others. */
    public function test_no_sede_still_blocks_the_hub(): void
    {
        // STAFF, not OWNER: LocationSwitcher gives an owner every sede in the org, so an owner can never BE
        // in the no-sede state and the test would have passed against a hub that ignored the chain entirely.
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([]);
        $this->actingAs($user);
        CounterOperator::set($user);

        $html = Livewire::test(CounterHome::class)->html();

        $this->assertStringContainsString('data-blocker="sede"', $html);
        $this->assertStringNotContainsString('data-counter-home-tile=', $html, 'the hub is not a way around a precondition');
    }
}
