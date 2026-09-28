<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\StrainType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Category;
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
 * Prompt 293 — the counter's round-trip budget, in the suite. A club-sized catalogue (40 genetics, 50 articles) and then
 * double (80 / 100): every basket, member and payment action on the Dispensario and the Barra returns at most 40 KB, and
 * the size does not grow with the catalogue (within 10 %). Before, each returned the whole screen — 160–175 KB on the
 * dispensary, 94–99 KB on the bar — and grew with every genetic a club added.
 *
 * Two responses carry the catalogue on purpose, because what it shows changed, and are measured but not budgeted: the
 * first socio identified on an empty counter (the working screen appears — before it the pane is not on the page at
 * all) and a commit (the stock on the cards moved). `tests/Browser/prove-293-budget.mjs` is the same budget in a real
 * browser, where the view-only controls must also make zero requests.
 */
class CounterRoundTripBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET_KB = 40;

    private Organisation $org;

    private Location $location;

    /** @var list<Genetic> */
    protected array $genetics = [];

    /** @var list<Article> */
    private array $articles = [];

    public function test_basket_member_and_payment_actions_stay_within_budget_and_do_not_grow_with_the_catalogue(): void
    {
        $this->club();
        $this->grow(40, 50);
        $small = [...$this->dispensary(), ...$this->bar()];

        $this->grow(80, 100);
        $double = [...$this->dispensary(), ...$this->bar()];

        foreach ($small as $action => $kb) {
            if (str_starts_with($action, 'unbudgeted:')) {
                continue;
            }
            $this->assertLessThanOrEqual(self::BUDGET_KB, $kb, "{$action} returned {$kb} KB (budget ".self::BUDGET_KB.' KB)');
            $this->assertLessThanOrEqual(self::BUDGET_KB, $double[$action], "{$action} returned {$double[$action]} KB at double size");
            $this->assertLessThanOrEqual($kb * 1.10, $double[$action], "{$action} grew from {$kb} KB to {$double[$action]} KB with the catalogue doubled");
        }

        // The catalogue IS on the page when it first appears — the budget is about not re-sending it, not hiding it.
        $this->assertGreaterThan(self::BUDGET_KB, $small['unbudgeted: first socio on an empty counter']);
    }

    public function test_the_catalogue_is_sent_once_and_then_skipped(): void
    {
        $this->club();
        $this->grow(40, 50);

        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id);
        $this->assertSame(40, substr_count($pos->html(), 'data-catalogue-item="genetics"'), 'the whole catalogue arrives with the socio');
        $this->assertSame(50, substr_count($pos->html(), 'data-catalogue-item="bar"'));

        $pos->call('chooseGenetic', $this->genetics[0]->id)->call('addLine', '1', 'grams');
        $this->assertStringNotContainsString('data-catalogue-item', $pos->html(), 'a basket action re-sent the catalogue');
        $this->assertStringContainsString('mode=skip', $pos->html(), 'the island is skipped, not dropped');

        // Something the pane SHOWS changes (a price edited in the panel mid-visit) → the next action carries it.
        GeneticPrice::query()->withoutGlobalScopes()->where('genetic_id', $this->genetics[1]->id)->update(['price_per_gram_cents' => 1234]);
        $pos->call('cancelWeightEntry');
        $this->assertSame(40, substr_count($pos->html(), 'data-catalogue-item="genetics"'));
    }

    protected function club(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    /** Grow the sede's catalogue to exactly `$genetics` sellable genetics and `$articles` articles. */
    protected function grow(int $genetics, int $articles): void
    {
        $flowerCategories = Category::factory()->count(4)->create(['organisation_id' => $this->org->id]);
        $barCategories = Category::factory()->count(5)->create(['organisation_id' => $this->org->id]);
        $strains = StrainType::cases();

        for ($i = count($this->genetics); $i < $genetics; $i++) {
            $genetic = Genetic::factory()->create([
                'organisation_id' => $this->org->id, 'name' => sprintf('Variedad %03d', $i + 1),
                'category_id' => $flowerCategories[$i % 4]->id, 'strain_type' => $strains[$i % count($strains)],
            ]);
            GeneticPrice::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
                'tier_id' => null, 'price_per_gram_cents' => 800 + $i, 'active' => true,
            ]);
            Batch::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
                'initial_cg' => 100000, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
            ]);
            $this->genetics[] = $genetic;
        }

        for ($i = count($this->articles); $i < $articles; $i++) {
            $this->articles[] = Article::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'name' => sprintf('Artículo %03d', $i + 1), 'category_id' => $barCategories[$i % 5]->id,
                'price_cents' => 150 + $i, 'stock' => 500, 'active' => true,
            ]);
        }
    }

    protected function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /** @return array<string, float> KB per action */
    private function dispensary(): array
    {
        $kb = [];
        $pos = Livewire::test(DispensaryPos::class);
        $measure = function (string $action, callable $act) use (&$kb, $pos): void {
            $act($pos);
            $kb[$action] = $this->kb($pos);
        };

        $measure('unbudgeted: first socio on an empty counter', fn (Testable $p) => $p->call('selectMember', $this->member()->id));
        $measure('pos: another socio', fn (Testable $p) => $p->call('selectMember', $this->member()->id));
        $measure('pos: choose genetic', fn (Testable $p) => $p->call('chooseGenetic', $this->genetics[0]->id));
        $measure('pos: cancel weight entry', fn (Testable $p) => $p->call('cancelWeightEntry'));
        $pos->call('chooseGenetic', $this->genetics[1]->id);
        $measure('pos: add to basket', fn (Testable $p) => $p->call('addLine', '1', 'grams'));
        $pos->call('chooseGenetic', $this->genetics[2]->id)->call('addLine', '1', 'grams');
        $measure('pos: remove line', fn (Testable $p) => $p->call('removeLine', 1));
        $measure('pos: add bar article', fn (Testable $p) => $p->call('addBarItem', $this->articles[0]->id));
        $measure('pos: type cash tendered', fn (Testable $p) => $p->set('cashTendered', '20'));
        $measure('pos: quick cash', fn (Testable $p) => $p->call('quickCash', 2000));
        $measure('pos: clear tendered', fn (Testable $p) => $p->call('clearTendered'));
        $pos->set('cashTendered', '50');
        $measure('unbudgeted: commit', fn (Testable $p) => $p->call('commitDispensation'));
        $measure('pos: clear socio', fn (Testable $p) => $p->call('clearMember', true));

        return $kb;
    }

    /** @return array<string, float> KB per action */
    private function bar(): array
    {
        $kb = [];
        $bar = Livewire::test(BarPos::class);
        $measure = function (string $action, callable $act) use (&$kb, $bar): void {
            $act($bar);
            $kb[$action] = $this->kb($bar);
        };

        $measure('bar: add article', fn (Testable $b) => $b->call('addArticle', $this->articles[0]->id));
        $measure('bar: +1', fn (Testable $b) => $b->call('incrementLine', 0));
        $measure('bar: -1', fn (Testable $b) => $b->call('decrementLine', 0));
        $bar->call('addArticle', $this->articles[1]->id);
        $measure('bar: remove line', fn (Testable $b) => $b->call('removeLine', 1));
        $measure('bar: type cash tendered', fn (Testable $b) => $b->set('cashTendered', '5'));
        $measure('bar: quick cash', fn (Testable $b) => $b->call('quickCash', 500));
        $measure('bar: clear tendered', fn (Testable $b) => $b->call('clearTendered'));

        return $kb;
    }

    /** The size of the Livewire response for the last request, as the wire carries it. */
    private function kb(Testable $testable): float
    {
        $payload = json_encode(['components' => [['snapshot' => json_encode($testable->snapshot), 'effects' => $testable->effects]], 'assets' => []]);

        return round(strlen((string) $payload) / 1024, 1);
    }
}
