<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 224 — bar lines were held server-side and rendered nowhere.
 *
 * The owner, on `/counter/pos` with a member attached and the Barra source selected: *"something strange —
 * it lets me add flower but no bar stuff. I don't know if it's because membership is due."*
 *
 * **The fee was innocent**, and this file starts by proving it: the member below has a €0 fee and a clean
 * verdict, and the defect reproduces exactly. What was wrong is one nesting. The cart's bar section — and
 * the tender under it — sat inside `@if (! empty($basketLines))`, the DISPENSATION basket. Before prompt 212
 * that held by construction, because the bar quick-add chips lived inside the same block: a bar line could
 * not exist without a flower line above it. 212 moved bar browsing to the centre pane, reachable with an
 * empty flower basket, and this gate was never updated. So `addBarItem` fired, returned 200, incremented a
 * server-side basket — and the screen showed nothing at all. Repeated taps stacked quantity invisibly.
 *
 * Prompt 60 from the other side: the control was not dead, the RESULT was silent.
 *
 * The guard is the four-state loop at the bottom. Each section gates on its OWN contents, so the next section
 * added to this cart cannot inherit somebody else's emptiness.
 */
class CartSectionsGateOnTheirOwnLinesTest extends TestCase
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

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        // Idempotent: the four-state loop below builds the screen four times, and a second OpenTill on the
        // same terminal is refused by design.
        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    private function genetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    /** A socio with NOTHING outstanding — the fee hypothesis, disproven by construction. */
    private function cleanMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function article(string $name = 'Cerveza', int $priceCents = 250): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => $priceCents, 'stock' => 100, 'active' => true,
        ]);
    }

    /** The dispensary with a till open and a clean socio attached — the owner's exact starting state. */
    private function dispensary(): Testable
    {
        $this->operator();

        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->cleanMember()->id);
    }

    // --- The reported bug -------------------------------------------------------------------------

    /**
     * **A bar article added with an empty flower basket appears on screen.**
     *
     * Fails against `ad871fe`: the add works and the section is not in the DOM, so the line, its total and
     * the tender are all missing.
     */
    public function test_a_bar_article_added_with_an_empty_flower_basket_is_rendered(): void
    {
        $article = $this->article('Cerveza', 250);

        $component = $this->dispensary()
            ->call('setCatalogueSource', 'bar')
            ->call('addBarItem', $article->id);

        // The server took it — which it always did.
        $this->assertCount(1, $component->get('barBasket'));

        $html = $component->html();

        $this->assertStringContainsString('data-cart-bar-section', $html, 'the bar section is still not rendered');
        $this->assertStringContainsString('Cerveza', $html, 'the line the operator tapped is not on screen');
        $this->assertStringContainsString(e(Money::fromCents(250)->formatted()), $html, 'the amount added to the visit is not on screen');
        $this->assertStringContainsString('data-settle-visit', $html, 'there is no way to take the money');
    }

    /**
     * …and the fee was innocent: this socio owes nothing and is clear to dispense.
     *
     * Asserted on what the SCREEN says, because that is what the owner was reading: no fee-due panel, no
     * blocked message, and the flower half working normally. The bar defect reproduces beside all of that.
     */
    public function test_the_member_reproducing_it_has_nothing_outstanding(): void
    {
        $genetic = $this->genetic();

        $html = $this->dispensary()
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->html();

        $this->assertStringNotContainsString(e(__('No se puede dispensar a este socio.')), $html, 'this socio is blocked — the fixture is wrong');
        $this->assertStringNotContainsString('data-inline-fee', $html, 'this socio owes a fee — the fixture is wrong');
        $this->assertStringContainsString(e(__('Total aportación')), $html, 'the flower half does not work either');
    }

    /** Tapping twice shows a quantity of two, rather than incrementing something invisible. */
    public function test_a_second_tap_is_visible_as_a_quantity(): void
    {
        $article = $this->article('Cerveza', 250);

        $html = $this->dispensary()
            ->call('setCatalogueSource', 'bar')
            ->call('addBarItem', $article->id)
            ->call('addBarItem', $article->id)
            ->html();

        $this->assertStringContainsString('2× Cerveza', $html, 'the second tap is still invisible');
        $this->assertStringContainsString(e(Money::fromCents(500)->formatted()), $html, 'the doubled amount is not shown');
    }

    // --- The tender tells the truth ---------------------------------------------------------------

    /**
     * The tender preview is split over the COMBINED total.
     *
     * It used to split the dispensation total alone while `settleWithBar()` splits dispensation + bar, so a
     * mixed visit showed a cash figure that understated what the settle would take, and a bar-only visit
     * showed €0,00 against real money.
     */
    public function test_the_cash_preview_covers_both_baskets(): void
    {
        $genetic = $this->genetic();
        $article = $this->article('Cerveza', 250);

        $component = $this->dispensary()
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->call('addBarItem', $article->id);

        // 2g at €8.00 = €16.00 plus a €2.50 beer = €18.50 to collect.
        $this->assertStringContainsString(e(Money::fromCents(1850)->formatted()), $component->html(), 'the cash to collect does not include the bar');
    }

    /** "Justo" fills in the combined cash owed, not the flower half of it. */
    public function test_quick_cash_offers_the_combined_total(): void
    {
        $genetic = $this->genetic();
        $article = $this->article('Cerveza', 250);

        $component = $this->dispensary()
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->call('addBarItem', $article->id)
            ->call('quickCash');

        $this->assertSame('18.50', $component->get('cashTendered'));
    }

    // --- A bar-only visit settles -----------------------------------------------------------------

    /**
     * **A bar-only visit settles from the dispensary**: an order row, no dispensation row, the cash in the
     * drawer.
     *
     * This needed a server change and the prompt asked for none — see DECISIONS. `CommitCombinedSettle`
     * refuses a one-sided settle in terms (*"an empty side means the caller wants a plain dispensation or a
     * plain order, which have their own single-writer entry points"*), so `settleWithBar()` now takes that
     * named entry point — `CommitOrder`, the same writer the Barra screen calls — for the bar-only case. The
     * combined path is untouched. Without it the fix would be half a fix: the operator can see the line and
     * cannot take the money without adding a flower line they do not want.
     */
    public function test_a_bar_only_visit_settles_into_the_bar_ledger(): void
    {
        $article = $this->article('Cerveza', 250);
        $component = $this->dispensary();

        $till = TillSession::query()->withoutGlobalScopes()->firstOrFail();
        $expectedBefore = TillSummary::breakdown($till)['expected'];

        $component
            ->call('setCatalogueSource', 'bar')
            ->call('addBarItem', $article->id)
            ->call('addBarItem', $article->id)
            ->call('settleWithBar')
            ->assertSet('barBasket', []);

        $order = Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertSame(500, $order->total_cents->cents, 'the order total is not two beers');
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count(), 'a bar-only visit wrote a dispensation');

        // The drawer: €5,00 more than before, derived from the ledger and never stored.
        $this->assertSame($expectedBefore + 500, TillSummary::breakdown($till->fresh())['expected']);
    }

    /** A mixed visit still settles BOTH ledgers — 118's combined path, unchanged. */
    public function test_a_mixed_visit_still_settles_both_ledgers(): void
    {
        $genetic = $this->genetic();
        $article = $this->article('Cerveza', 250);

        $this->dispensary()
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->call('addBarItem', $article->id)
            ->call('settleWithBar');

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count(), 'the dispensation half is missing');
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count(), 'the bar half is missing');
        $this->assertSame(1600, Dispensation::query()->withoutGlobalScopes()->firstOrFail()->total_cents->cents);
        $this->assertSame(250, Order::query()->withoutGlobalScopes()->firstOrFail()->total_cents->cents);
    }

    // --- THE GUARD FOR THE CLASS ------------------------------------------------------------------

    /**
     * **Section visibility as a function of each section's OWN lines**, across all four states.
     *
     * One loop, four states, so the next section added to this cart cannot inherit somebody else's gate —
     * which is precisely how the bar section came to depend on the flower basket and stayed that way through
     * two prompts.
     */
    public function test_each_section_is_visible_exactly_when_its_own_lines_exist(): void
    {
        $genetic = $this->genetic();
        $article = $this->article('Cerveza', 250);

        $states = [
            'neither' => ['flower' => false, 'bar' => false],
            'flower only' => ['flower' => true, 'bar' => false],
            'bar only' => ['flower' => false, 'bar' => true],
            'both' => ['flower' => true, 'bar' => true],
        ];

        foreach ($states as $name => $state) {
            // The source stays on the dispensary throughout, so "the bar section is visible" can only be
            // explained by bar LINES and never by which catalogue is being browsed.
            $component = $this->dispensary();

            if ($state['flower']) {
                $component->call('chooseGenetic', $genetic->id)->set('weightInput', '2')->call('addLine');
            }
            if ($state['bar']) {
                $component->call('addBarItem', $article->id);
            }

            $html = $component->html();
            $has = fn (string $needle): bool => str_contains($html, $needle);

            // The dispensation section is always the cart's frame; its TOTAL follows its own lines.
            $this->assertTrue($has('data-cart-dispensation-section'), "{$name}: the cart frame vanished");
            $this->assertSame($state['flower'], $has(e(__('Total aportación'))), "{$name}: the aportación total is wrong");

            // The bar section follows its own lines (the source is dispensario in every state here, and a
            // flower basket keeps the signpost — the one designed empty state).
            $this->assertSame($state['flower'] || $state['bar'], $has('data-cart-bar-section'), "{$name}: the bar section is wrong");
            $this->assertSame($state['bar'], $has(e(__('Total barra y tienda'))), "{$name}: the bar total is wrong");
            $this->assertSame($state['bar'], $has('data-settle-visit'), "{$name}: the settle control is wrong");

            // The tender follows EITHER — there is money to take whenever either side has lines.
            $this->assertSame($state['flower'] || $state['bar'], $has(e(__('Efectivo entregado'))), "{$name}: the tender is wrong");
            $this->assertSame(! $state['flower'] && ! $state['bar'], $has('data-empty-basket-hint'), "{$name}: the empty hint is wrong");

            // The commit is ALWAYS reachable (prompt 60), whatever the baskets hold.
            $this->assertTrue($has('data-commit-action'), "{$name}: the commit left the screen");
        }
    }

    /** A sede with no bar renders no bar section in any state. */
    public function test_a_sede_with_no_bar_renders_no_bar_section(): void
    {
        Settings::set('bar_enabled', false, SettingType::BOOL, $this->location->id);

        $genetic = $this->genetic();

        $html = $this->dispensary()
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->html();

        $this->assertStringNotContainsString('data-cart-bar-section', $html);
        $this->assertStringContainsString(e(__('Total aportación')), $html, 'the aportación total went with it');
    }
}
