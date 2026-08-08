<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 212 — one catalogue, two sources.
 *
 * The owner, on `/counter/pos`: *"to add bar products to the same transaction you only got a couple of
 * choices on the side. Instead you should have full access — where it says Genetics, maybe have a toggle to
 * bar products. Also instead of saying Genetics, change it."*
 *
 * **The premise is right and the reason is worse than "a couple of choices."** `barArticleRows()` was never
 * capped: it returned every active in-stock article at the sede and the cart rendered each as a `+ Name`
 * chip. Five today because the seed has five; a club with forty gets forty chips stacked in the narrow column
 * that already carries the member, the basket, the tender and the commit — no search, no categories, no
 * prices, no stock. It had **no browsing model at all**, and it degraded as the club grew rather than as it
 * shrank.
 *
 * **The line this must not blur:** the toggle changes what you are BROWSING, never which basket you are
 * filling. A genetic tap is still a dispensation line through `CommitDispensation`; an article tap is still a
 * bar line on its own ledger (prompt 118). If an operator can be unsure which half of the visit they just
 * added to, this made the screen worse.
 */
class OneCatalogueTwoSourcesTest extends TestCase
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

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);

        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
            'fee_cents' => 0,
        ]);

        return $member;
    }

    private function genetic(): Genetic
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

    /**
     * The POS with a socio held.
     *
     * The catalogue pane sits behind the MEMBER blocker on this screen and always has: the dispensary is a
     * member-first weight POS, and the standalone Bar screen is the one that serves guests (and keeps no
     * member blocker for that reason). 212 does not change that — it changes what the pane can browse.
     */
    private function posWithMember(): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id);
    }

    private function article(string $name, array $attributes = []): Article
    {
        return Article::factory()->create(array_merge([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => 250, 'stock' => 20, 'active' => true,
        ], $attributes));
    }

    // --- The reported defect ------------------------------------------------------------------

    /**
     * **Every** active, in-stock article at the sede is reachable — not just the ones that fitted.
     *
     * Seeded well past what any pane shows at once, and the LAST one found by search. Fails against `main`:
     * there the only way to an article is a chip in the cart column, so "reachable" means "rendered", and
     * forty chips in a narrow column is the defect rather than the fix.
     */
    public function test_every_article_is_reachable_from_the_dispensary(): void
    {
        $this->operator();

        for ($i = 1; $i <= 40; $i++) {
            $this->article(sprintf('Artículo %02d', $i));
        }
        $last = $this->article('Zumo de naranja exprimido');

        $pos = $this->posWithMember()->call('setCatalogueSource', 'bar');

        $pos->set('articleSearch', 'Zumo')
            ->assertSee('data-bar-article="'.$last->id.'"', false)
            ->assertDontSee('Artículo 01');

        // …and with no search every one of them is in the source, not a subset that fitted.
        $pos->set('articleSearch', '');
        $this->assertSame(41, substr_count($pos->html(), 'data-bar-article='), 'the catalogue is capped');
    }

    /** The chip list is gone from the cart — browsing lives in the pane that can browse. */
    public function test_the_cart_no_longer_lists_the_whole_catalogue(): void
    {
        $this->operator();
        $this->article('Cerveza sin alcohol');

        $html = $this->posWithMember()->html();

        // The article is offered by the pane, and the cart column does not enumerate the catalogue.
        $this->assertStringNotContainsString('+ Cerveza sin alcohol', $html, 'the chip list is still there');
    }

    // --- The line that must not blur -----------------------------------------------------------

    /**
     * A genetic tap still produces a DISPENSATION line and an article tap a BAR line, in the same visit,
     * settled together — asserted against both tables.
     */
    public function test_each_source_fills_its_own_half_of_the_visit(): void
    {
        $this->operator();
        $member = $this->member();
        $genetic = $this->genetic();
        $article = $this->article('Cerveza sin alcohol');

        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,00')
            ->call('addLine')
            ->call('setCatalogueSource', 'bar')
            ->call('addBarItem', $article->id);

        $this->assertCount(1, $pos->get('basket'), 'the genetic did not land on the dispensation basket');
        $this->assertCount(1, $pos->get('barBasket'), 'the article did not land on the bar basket');

        $pos->set('cashTendered', '100,00')->call('settleWithBar');

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->where('member_id', $member->id)->count());
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('member_id', $member->id)->count());
    }

    /** The cart keeps two clearly separate sections. */
    public function test_the_cart_keeps_two_labelled_sections(): void
    {
        $this->operator();
        $member = $this->member();
        $genetic = $this->genetic();

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,00')
            ->call('addLine')
            ->html();

        $this->assertStringContainsString('data-cart-dispensation-section', $html);
        $this->assertStringContainsString('data-cart-bar-section', $html);
        // `e()`, because Blade escapes — the English label is "Bar & shop (same visit)" and renders `&amp;`.
        $this->assertStringContainsString(e(__('Total aportación')), $html);
        $this->assertStringContainsString(e(__('Barra y tienda (misma visita)')), $html);
    }

    // --- Switching source disturbs nothing ------------------------------------------------------

    /**
     * Switching source does not disturb the basket, the member, the tender or a weight entry in progress.
     *
     * If an operator can lose work by looking at the other half of the catalogue, this branch made the screen
     * worse rather than better.
     */
    public function test_switching_source_disturbs_nothing_in_progress(): void
    {
        $this->operator();
        $member = $this->member();
        $genetic = $this->genetic();
        $article = $this->article('Cerveza sin alcohol');

        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2,50')
            ->call('addLine')
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '1,25')
            ->set('cashTendered', '20,00');

        $pos->call('setCatalogueSource', 'bar')
            ->assertSet('memberId', $member->id)
            ->assertSet('weightInput', '1,25')
            ->assertSet('cashTendered', '20,00')
            ->assertSet('activeGeneticId', $genetic->id);
        $this->assertCount(1, $pos->get('basket'));

        // …and back again, having added a bar line in between.
        $pos->call('addBarItem', $article->id)
            ->call('setCatalogueSource', 'genetics')
            ->assertSet('weightInput', '1,25')
            ->assertSet('cashTendered', '20,00');
        $this->assertCount(1, $pos->get('basket'));
        $this->assertCount(1, $pos->get('barBasket'));
    }

    /** Each source keeps its own search term, so a glance at the other half loses neither. */
    public function test_each_source_keeps_its_own_search(): void
    {
        $this->operator();
        $this->article('Cerveza sin alcohol');

        $this->posWithMember()
            ->set('geneticSearch', 'kush')
            ->call('setCatalogueSource', 'bar')
            ->set('articleSearch', 'cerveza')
            ->call('setCatalogueSource', 'genetics')
            ->assertSet('geneticSearch', 'kush')
            ->assertSet('articleSearch', 'cerveza');
    }

    // --- What must not be offered ---------------------------------------------------------------

    /** An article that is inactive or out of stock is not offered — refused in the query, never rendered. */
    public function test_an_inactive_or_empty_article_is_not_offered(): void
    {
        $this->operator();
        $sellable = $this->article('Cerveza sin alcohol');
        $inactive = $this->article('Retirado', ['active' => false]);
        $empty = $this->article('Agotado', ['stock' => 0]);

        $html = $this->posWithMember()->call('setCatalogueSource', 'bar')->html();

        $this->assertStringContainsString('data-bar-article="'.$sellable->id.'"', $html);
        $this->assertStringNotContainsString('data-bar-article="'.$inactive->id.'"', $html);
        $this->assertStringNotContainsString('data-bar-article="'.$empty->id.'"', $html);
    }

    /** A sede with no bar offers no bar source at all, rather than an empty one. */
    public function test_a_sede_with_no_bar_has_no_bar_source(): void
    {
        $this->operator();
        $this->article('Cerveza sin alcohol');
        Settings::set('bar_enabled', false, SettingType::BOOL, $this->location->id);

        $pos = $this->posWithMember();
        $html = $pos->html();

        $this->assertStringNotContainsString('data-source-option="bar"', $html, 'a sede with no bar was offered one');
        $this->assertStringContainsString('data-source-option="genetics"', $html);

        // …and it cannot be reached by asking for it either.
        $pos->call('setCatalogueSource', 'bar')->assertSet('catalogueSource', 'genetics');
    }

    /** 185: the bar card states a stock STATE, never a published quantity. */
    public function test_the_bar_card_states_stock_rather_than_publishing_it(): void
    {
        $this->operator();
        // 7 left, low below 9, priced 3,55 € — no digit of the price or the threshold is a 7, so a 7 in the
        // card's TEXT could only be the stock count itself.
        $this->article('Casi agotado', ['stock' => 7, 'low_stock_threshold' => 9, 'price_cents' => 355]);

        $html = $this->posWithMember()->call('setCatalogueSource', 'bar')->html();
        $at = strpos($html, 'data-bar-article=');
        $this->assertNotFalse($at);
        $card = substr($html, $at, (int) strpos($html, '</button>', $at) - $at);

        $this->assertStringContainsString('data-bar-stock-state', $card, 'the low state is not shown at all');
        $this->assertStringContainsString(__('Quedan pocas'), $card);

        // The STATE, never the figure — 185's rule, asserted against the card's visible TEXT (the attributes
        // above it are full of Tailwind digits and a ULID).
        $text = strip_tags(substr($card, (int) strpos($card, '>')));
        $this->assertDoesNotMatchRegularExpression('/\b7\b/', $text, 'the raw stock count reached the card');
        $this->assertStringContainsString('3', $text, 'the price is missing, so the card is not readable');
        $this->assertStringContainsString('55', $text);
    }

    // --- The pane's own furniture ---------------------------------------------------------------

    /**
     * Which of the pane's filters apply to articles: Categoría does, Tipo and Variedad do not — they are
     * facts about cannabis and would render as empty rows. Hidden for that source rather than shown empty.
     */
    public function test_the_panes_furniture_follows_the_source(): void
    {
        $this->operator();
        $this->genetic();
        $category = Category::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Refrescos']);
        $this->article('Cerveza sin alcohol', ['category_id' => $category->id]);

        $bar = $this->posWithMember()->call('setCatalogueSource', 'bar')->html();

        $this->assertStringContainsString('Refrescos', $bar, 'the bar has no category filter');
        $this->assertStringNotContainsString(__('Variedad'), $bar, 'the strain filter rendered on the bar source');
        $this->assertStringNotContainsString('data-usual-genetics', $bar, '"Their usual" rendered on the bar source');
    }

    /** The category filter really filters the bar source. */
    public function test_the_bar_category_filter_filters(): void
    {
        $this->operator();
        $drinks = Category::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Refrescos']);
        $merch = Category::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Merchandising']);
        $drink = $this->article('Cerveza sin alcohol', ['category_id' => $drinks->id]);
        $shirt = $this->article('Camiseta', ['category_id' => $merch->id]);

        $pos = $this->posWithMember()
            ->call('setCatalogueSource', 'bar')
            ->call('filterArticleCategory', $drinks->id);

        $html = $pos->html();
        $this->assertStringContainsString('data-bar-article="'.$drink->id.'"', $html);
        $this->assertStringNotContainsString('data-bar-article="'.$shirt->id.'"', $html);
    }

    /** 194: a catalogue search is not a member search. */
    public function test_neither_source_adds_a_second_member_lookup(): void
    {
        $this->operator();
        $this->article('Cerveza sin alcohol');

        foreach (['genetics', 'bar'] as $source) {
            $html = $this->posWithMember()->call('setCatalogueSource', $source)->html();

            $this->assertLessThanOrEqual(
                1,
                preg_match_all('/data-member-lookup(?![-\w])/', $html),
                $source.': a second member lookup',
            );
        }
    }
}
