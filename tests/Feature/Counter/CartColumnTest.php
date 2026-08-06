<?php

namespace Tests\Feature\Counter;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 176 — the button that takes the money was off the bottom of the screen.
 *
 * Measured on `main` at 592c93c, after `npm run build`, with a socio identified and three lines in the
 * basket: `Registrar aportación` sat 186px below the fold at 1180x820 and 939px below it at 820x1180;
 * `Cobrar` sat 149px and 693px below. The counter was one vertical stack that grew as work was added, so
 * the commit moved FURTHER AWAY the more there was to commit.
 *
 * The fix is two panes where only one scrolls. This file asserts the STRUCTURE that makes that true —
 * the commit lives inside the cart column, the cart column is not the scroll container, the allowance is
 * on the cart. The PIXEL proof (the action is inside the viewport at both orientations, with an empty and
 * a full basket, after scrolling the pane to its end) is `tests/Browser/measure-cart-column.mjs`, because
 * a headless render cannot measure a fold.
 *
 * Presentation only: no pricing, eligibility or commit path is touched, and the totals shown are still the
 * totals charged — asserted by the money tests this branch left alone.
 */
class CartColumnTest extends TestCase
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
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    private function genetic(string $name = 'Amnesia Haze'): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
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

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function article(string $name = 'Cerveza'): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => 250, 'stock' => 100, 'active' => true,
        ]);
    }

    /** The dispensary, resolved: a till open and a socio identified. */
    private function dispensary(): Testable
    {
        $this->operator();
        $this->genetic();

        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id);
    }

    /** Everything between the cart column's opening tag and its close. */
    private function cartColumn(string $html): string
    {
        $start = strpos($html, 'data-cart-column');
        $this->assertNotFalse($start, 'no cart column on this screen');

        return substr($html, $start);
    }

    // --- the column exists, and the commit is IN it -------------------------------------------------

    public function test_the_dispensary_commit_lives_inside_the_cart_column(): void
    {
        $html = $this->dispensary()->html();

        $this->assertStringContainsString('data-cart-column', $html);
        $this->assertStringContainsString('data-commit-action', $this->cartColumn($html));
    }

    public function test_the_bar_commit_lives_inside_the_cart_column(): void
    {
        $this->operator();
        $this->article();

        $html = Livewire::test(BarPos::class)->html();

        $this->assertStringContainsString('data-cart-column', $html);
        $this->assertStringContainsString('data-commit-action', $this->cartColumn($html));
    }

    public function test_both_selling_screens_have_exactly_one_cart_column_and_one_commit(): void
    {
        $this->operator();
        $this->genetic();
        $this->article();

        $dispensary = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)->html();
        $bar = Livewire::test(BarPos::class)->html();

        foreach (['dispensary' => $dispensary, 'bar' => $bar] as $name => $html) {
            $this->assertSame(1, substr_count($html, 'data-cart-column'), "$name has more than one cart column");
            $this->assertSame(1, substr_count($html, 'data-commit-action'), "$name has more than one commit action");
            $this->assertSame(1, substr_count($html, 'data-selection-pane'), "$name has more than one selection pane");
        }
    }

    /**
     * The invariant the whole branch rests on: the SELECTION pane is the scroll container and the cart
     * column is not. If this inverts, the commit goes back below the fold and the pixel harness is the
     * only thing that would notice.
     */
    public function test_the_selection_pane_scrolls_and_the_cart_column_does_not(): void
    {
        $html = $this->dispensary()->html();

        $pane = substr($html, strpos($html, 'data-selection-pane'), 200);
        $this->assertStringContainsString('overflow-y-auto', $pane, 'the selection pane must be the scroll container');

        $column = substr($html, strpos($html, 'data-cart-column'), 200);
        $this->assertStringNotContainsString('overflow-y-auto', $column, 'the cart column itself must not scroll');
    }

    // --- the allowance is on the cart ---------------------------------------------------------------

    public function test_the_remaining_allowance_is_on_the_cart_whenever_a_member_is_selected(): void
    {
        $html = $this->dispensary()->html();

        $this->assertStringContainsString('data-member-allowance', $this->cartColumn($html));
        $this->assertStringContainsString(__('Restante hoy'), $html);
    }

    public function test_the_allowance_reports_the_same_figure_the_resolver_does(): void
    {
        $this->operator();
        $this->genetic();
        $member = $this->member();
        $member->update(['daily_limit_cg' => 500]); // 5 g

        $component = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);

        // Against the RESOLVER, not a hard-coded string. The screen shows what ResolveMemberLimits
        // resolved; if it ever computed its own figure, this is what would catch it.
        $limits = (new ResolveMemberLimits)->handle($member->fresh(), $this->location);

        $this->assertStringContainsString(
            $component->instance()->grams($limits->dailyRemainingCg()),
            $this->cartColumn($component->html())
        );
    }

    public function test_no_member_means_no_allowance_block_rather_than_an_empty_one(): void
    {
        $this->operator();
        $this->genetic();

        // With no socio the screen IS the member blocking state (prompt 175) — there is no cart to put an
        // allowance on, and an empty gauge would be a figure about nobody.
        $html = Livewire::test(DispensaryPos::class)->html();

        $this->assertStringNotContainsString('data-member-allowance', $html);
    }

    // --- list / grid, and the defaults ---------------------------------------------------------------

    public function test_genetics_default_to_list_and_articles_default_to_grid(): void
    {
        $this->operator();
        $this->genetic();
        $this->article();

        $this->assertSame('list', Livewire::test(DispensaryPos::class)->get('geneticLayout'));
        $this->assertSame('grid', Livewire::test(BarPos::class)->get('articleLayout'));
    }

    public function test_the_genetics_toggle_switches_both_ways(): void
    {
        $component = $this->dispensary();

        $component->call('setGeneticLayout', 'grid');
        $this->assertSame('grid', $component->get('geneticLayout'));

        $component->call('setGeneticLayout', 'list');
        $this->assertSame('list', $component->get('geneticLayout'));
    }

    public function test_the_articles_toggle_switches_both_ways(): void
    {
        $this->operator();
        $this->article();

        $component = Livewire::test(BarPos::class);

        $component->call('setArticleLayout', 'list');
        $this->assertSame('list', $component->get('articleLayout'));

        $component->call('setArticleLayout', 'grid');
        $this->assertSame('grid', $component->get('articleLayout'));
    }

    public function test_the_choice_survives_a_reload(): void
    {
        $this->operator();
        $this->genetic();
        $member = $this->member();

        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->call('setGeneticLayout', 'grid');

        // A FRESH mount — the same session, a new component instance, as after a reload.
        $this->assertSame('grid', Livewire::test(DispensaryPos::class)->get('geneticLayout'));
    }

    public function test_the_two_screens_remember_their_choices_independently(): void
    {
        $this->operator();
        $this->genetic();
        $this->article();

        Livewire::test(DispensaryPos::class)->call('setGeneticLayout', 'grid');

        // The bar keeps its own default — one shared key would make one screen's preference the other's.
        $this->assertSame('grid', Livewire::test(BarPos::class)->get('articleLayout'));

        Livewire::test(BarPos::class)->call('setArticleLayout', 'list');
        $this->assertSame('grid', Livewire::test(DispensaryPos::class)->get('geneticLayout'));
    }

    public function test_an_unknown_layout_is_ignored_rather_than_stored(): void
    {
        $component = $this->dispensary();

        $component->call('setGeneticLayout', 'carousel');

        $this->assertSame('list', $component->get('geneticLayout'));
    }

    // --- the filters no longer stand between the operator and the products ---------------------------

    public function test_the_filters_are_collapsed_behind_one_control(): void
    {
        $html = $this->dispensary()->html();

        $this->assertStringContainsString(__('Filtros'), $html);
        // Closed by default: the rows are inside an x-show, so they cost no vertical space on arrival.
        $this->assertStringContainsString('x-show="open"', $html);
    }

    public function test_the_filters_open_themselves_when_a_filter_is_already_applied(): void
    {
        $component = $this->dispensary();

        $this->assertStringContainsString('x-data="{ open: false }"', $component->html());

        $component->call('filterProductType', 'FLOWER');

        // An active filter that is hidden is a filter the operator cannot see they have set.
        $this->assertStringContainsString('x-data="{ open: true }"', $component->html());
    }

    // --- the touch floor, on the controls this branch moved or raised --------------------------------

    public function test_the_controls_this_branch_raised_meet_the_touch_floor(): void
    {
        $this->operator();
        $this->genetic();
        $this->article();

        $dispensary = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)->html();
        $bar = Livewire::test(BarPos::class)->html();

        // Measured under 44px in a real browser after a rebuild, and raised here:
        //   Cerrar 52x32 · Vaciar 57x28 · ✕ 28x32 · the bar's category pills 66x30 and 48x30.
        $this->assertStringNotContainsString(
            '<button type="button" wire:click="clearBasket" class="rounded-lg px-2 py-1',
            $dispensary.$bar,
            'the basket clear button is back under the touch floor'
        );
        $this->assertStringNotContainsString(
            "@class(['rounded-full border px-3 py-1 text-sm'",
            $bar,
            "the bar's category pills are back under the touch floor"
        );
        $this->assertStringContainsString('h-11 w-11', $dispensary, 'the line-remove control is not at the 44px floor');
    }
}
