<?php

namespace Tests\Feature\Counter;

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
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 293 — the catalogue's view controls never talk to the server.
 *
 * The Dispensario / Barra tab, the list / grid / large toggle, the Categoría / Tipo / Variedad filters and the search
 * boxes change only what is visible, so they are Alpine state over the catalogue already on the page. Each one used to
 * re-render and re-send the whole screen (~170 KB on the dispensary, ~99 KB on the bar). Every such control is marked
 * `data-view-only`, and a `wire:click` or a `wire:model` on one is refused — `tests/Browser/prove-293-budget.mjs` checks
 * the same thing in a real browser by counting requests.
 */
class ViewOnlyControlsTest extends TestCase
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
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $flowers = Category::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Flores']);
        $drinks = Category::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Bebidas']);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'category_id' => $flowers->id, 'strain_type' => StrainType::SATIVA]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);
        Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Agua', 'category_id' => $drinks->id, 'price_cents' => 150, 'stock' => 20, 'active' => true,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /**
     * Every `data-view-only` element that carries a Livewire directive — a view change that would make a request.
     *
     * @return list<string>
     */
    private static function violations(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        $found = [];
        foreach ((new DOMXPath($dom))->query('//*[@data-view-only]') ?: [] as $el) {
            /** @var DOMElement $el */
            foreach ($el->attributes ?? [] as $attribute) {
                if (preg_match('/^wire:(click|model|change|input|keydown|keyup|submit|blur)/', $attribute->nodeName)) {
                    $found[] = $el->nodeName.' '.$attribute->nodeName.'="'.$attribute->nodeValue.'"';
                }
            }
        }

        return $found;
    }

    /**
     * The view-only controls on a screen, by what they are.
     *
     * @return array<string, int>
     */
    private static function controls(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($dom);

        return [
            'tab' => $xpath->query('//*[@data-view-only][@data-source-option]')->length ?? 0,
            'layout' => $xpath->query('//*[@data-view-only][@data-layout-option]')->length ?? 0,
            'search' => $xpath->query('//input[@data-view-only]')->length ?? 0,
            'filter' => $xpath->query('//button[@data-view-only][not(@data-source-option)][not(@data-layout-option)]')->length ?? 0,
        ];
    }

    public function test_the_dispensary_view_controls_are_marked_and_make_no_request(): void
    {
        $html = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)->html();

        $controls = self::controls($html);
        $this->assertSame(2, $controls['tab'], 'Dispensario and Barra');
        $this->assertSame(2, $controls['layout'], 'list and grid');
        $this->assertSame(2, $controls['search'], 'one search box per source');
        // Categoría (Todas + Flores), Tipo (Todos + Flor), Variedad (Todas + Sativa), the bar's Categoría (Todas + Bebidas).
        $this->assertSame(8, $controls['filter']);
        $this->assertSame([], self::violations($html));
    }

    public function test_the_bar_view_controls_are_marked_and_make_no_request(): void
    {
        $html = Livewire::test(BarPos::class)->html();

        $controls = self::controls($html);
        $this->assertSame(3, $controls['layout'], 'list, grid and large');
        $this->assertSame(1, $controls['search']);
        $this->assertSame(4, $controls['filter'], 'Todo + Bebidas as tiles, and again as chips');
        $this->assertSame([], self::violations($html));
    }

    /** No template or card in the catalogue reaches for one of the old view-only server methods. */
    public function test_the_view_only_server_methods_are_gone(): void
    {
        foreach (['setCatalogueSource', 'filterCategory', 'filterArticleCategory', 'filterProductType', 'filterStrainType', 'setGeneticLayout'] as $method) {
            $this->assertFalse(method_exists(DispensaryPos::class, $method), "DispensaryPos::{$method} can still be called");
        }
        foreach (['setArticleLayout', 'filterCategory'] as $method) {
            $this->assertFalse(method_exists(BarPos::class, $method), "BarPos::{$method} can still be called");
        }

        foreach (['livewire/counter/dispensary-pos', 'livewire/counter/bar-pos', 'components/counter/article-card'] as $view) {
            $blade = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/wire:model(\.[a-z.0-9]+)?="(geneticSearch|articleSearch)"/', $blade, "{$view}: a search box posts again");
        }
    }

    /** The rule is proven by a planted violation: a filter chip wired back to the server fails it. */
    public function test_the_rule_catches_a_planted_wire_click(): void
    {
        $this->assertSame([], self::violations('<button type="button" data-view-only x-on:click="filter(\'category\', null)">Todas</button>'));

        $this->assertNotEmpty(self::violations('<button type="button" data-view-only wire:click="filterCategory(null)">Todas</button>'));
        $this->assertNotEmpty(self::violations('<input type="text" data-view-only wire:model.live.debounce.300ms="geneticSearch">'));
    }
}
