<?php

namespace Tests\Feature\Bar;

use App\Actions\Till\OpenTill;
use App\Enums\CategoryAppliesTo;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Category;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Concerns\FiltersTheCatalogueLikeTheBrowser;
use Tests\TestCase;

/**
 * Prompt 248 — the standalone Bar's third layout: `large` (big tiles, category-first), a toggle beside compact.
 *
 * A size, not a redesign: compact list and grid are byte-identical to before, `large` is additive. The choice
 * sticks to the DEVICE (the #[Session] property — survives a reload and a shift change) with a per-sede default
 * in Settings for a fresh terminal.
 */
class BarLargeLayoutTest extends TestCase
{
    use FiltersTheCatalogueLikeTheBrowser, RefreshDatabase;

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
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    private function category(string $name): Category
    {
        return Category::factory()->create([
            'organisation_id' => $this->org->id, 'name' => $name, 'applies_to' => CategoryAppliesTo::ARTICLE,
        ]);
    }

    private function article(string $name, int $stock, ?Category $category = null): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => 250, 'stock' => $stock, 'active' => true,
            'category_id' => $category?->id,
        ]);
    }

    // --- The third layout is accepted; unknown values are still ignored (176) ---

    public function test_large_is_accepted_and_unknown_values_are_ignored(): void
    {
        $this->operator();

        Livewire::test(BarPos::class)
            ->assertSet('articleLayout', 'grid') // the code default
            // Prompt 293 — the toggle is the browser's; its choice arrives as the property with the next request.
            ->set('articleLayout', 'large')
            ->assertSet('articleLayout', 'large')
            ->set('articleLayout', 'massive')  // not a layout
            ->assertSet('articleLayout', 'large')  // ignored, not stored
            ->set('articleLayout', 'list')
            ->assertSet('articleLayout', 'list');
    }

    // --- Large mode is category-first and filters exactly like the chips ---------

    public function test_large_mode_is_category_first_with_todo_and_filters_like_the_chips(): void
    {
        $this->operator();
        $drinks = $this->category('Bebidas');
        $sweets = $this->category('Chuches');
        $this->article('Café', 5, $drinks);
        $this->article('Gominolas', 5, $sweets);

        session(['counter.bar.article_layout' => 'large']);
        $html = (string) preg_replace('/\s+/', ' ', Livewire::test(BarPos::class)->html());

        // Category tiles, shown in large mode (the compact chips in the others): Todo + each category as big tiles.
        $this->assertMatchesRegularExpression('/data-category-tiles x-show="layoutOf\(\) === \'large\'"/', $html);
        $this->assertStringContainsString('data-category-tile', $html);
        $this->assertStringContainsString(__('Todo'), $html);
        $this->assertStringContainsString('Bebidas', $html);
        $this->assertStringContainsString('Chuches', $html);

        // Filtering is the chips' semantics exactly — a tile and a chip set the same category, in the browser
        // (prompt 293), and a card shows only while its own category matches; Todo is the null category.
        $this->assertSame(2, substr_count($html, "x-on:click=\"filter('category', '{$drinks->id}')\""), 'a tile and a chip, one filter');
        $this->assertSame(2, substr_count($html, "x-on:click=\"filter('category', null)\""));
        $this->assertSame(['Café'], $this->visibleInBrowser($html, 'bar', ['category' => ['bar' => $drinks->id]]));
        $this->assertSame(['Café', 'Gominolas'], $this->visibleInBrowser($html, 'bar'));
        unset($sweets);
    }

    public function test_sold_out_is_disabled_and_visible_in_large_mode(): void
    {
        $this->operator();
        // Named so it is not a UI string in either locale (prompt 253): 'Agotado' was also the es sold-out
        // label, so the name assertion passed on the label alone.
        $this->article('Tónica Probe', 0);

        session(['counter.bar.article_layout' => 'large']);
        $html = Livewire::test(BarPos::class)->html();

        // 230's rule holds at the new size: the sold-out article is shown, with its count, disabled.
        $this->assertStringContainsString(e('Tónica Probe'), $html);    // the article name (visible, not hidden)
        $this->assertStringContainsString('disabled', $html);           // the button carries the disabled attribute
        $this->assertStringContainsString('cursor-not-allowed', $html); // …and the sold-out card styling
        $this->assertStringContainsString(e(__('Agotado')), $html);     // the sold-out state, in the running locale
        $this->assertStringContainsString('data-layout="large"', $html, 'the catalogue is not in large mode');
        $this->assertStringContainsString('as-large:!min-h-[120px]', $html, 'the sold-out tile is not the large size');
    }

    // --- The choice sticks to the device ----------------------------------------

    public function test_the_choice_persists_across_a_reload(): void
    {
        $this->operator();

        Livewire::test(BarPos::class)->set('articleLayout', 'large');

        // #[Session] wrote it to the terminal's session — a fresh mount (a reload) reads it back.
        $this->assertSame('large', session('counter.bar.article_layout'));
        Livewire::test(BarPos::class)->assertSet('articleLayout', 'large');
    }

    public function test_a_fresh_device_adopts_the_sede_default(): void
    {
        // No stored choice + a sede default of large ⇒ a fresh terminal starts large.
        Settings::set('bar_layout_default', 'large', SettingType::STRING, $this->location->id);
        $this->operator();

        Livewire::test(BarPos::class)->assertSet('articleLayout', 'large');
    }

    public function test_the_default_is_grid_when_the_sede_has_not_chosen(): void
    {
        $this->operator();

        Livewire::test(BarPos::class)->assertSet('articleLayout', 'grid');
    }

    // --- One card, three sizes, chosen by the container (prompt 293) ---

    public function test_the_card_carries_each_size_under_its_own_variant_and_no_bare_large_token(): void
    {
        $article = ['id' => 'A1', 'name' => 'Café', 'price_label' => '€2,50', 'stock' => 5, 'low_stock' => false, 'category_name' => 'Bebidas', 'image_url' => null];

        $html = Blade::render('<x-counter.article-card :article="$article" action="addArticle" :thumbs="true" />', ['article' => $article]);

        // 225's compact forms, unchanged in substance: a row in list, a tile in grid — now keyed to the container's
        // `data-layout`, so the toggle is one attribute in the browser instead of a re-render of every card.
        $this->assertStringContainsString('flex w-full min-h-11 rounded-xl border px-3 py-1.5 text-left transition', $html);
        $this->assertStringContainsString('as-list:flex-row as-list:items-center as-list:gap-3', $html);
        $this->assertStringContainsString('as-grid:flex-col as-grid:gap-1', $html);

        // Every large-only token applies ONLY in large mode — none may leak into list or grid.
        foreach (['!min-h-[120px]', '!text-lg', '!text-xl', 'h-24', '!px-4'] as $largeToken) {
            $this->assertMatchesRegularExpression('/\sas-large:'.preg_quote($largeToken, '/').'/', $html, "large token $largeToken is missing");
            $this->assertDoesNotMatchRegularExpression('/[\s"]'.preg_quote($largeToken, '/').'/', $html, "large token $largeToken applies outside large mode");
        }
    }
}
