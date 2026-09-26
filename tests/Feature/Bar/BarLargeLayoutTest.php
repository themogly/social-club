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
            ->call('setArticleLayout', 'large')
            ->assertSet('articleLayout', 'large')
            ->call('setArticleLayout', 'massive')  // not a layout
            ->assertSet('articleLayout', 'large')  // ignored, not stored
            ->call('setArticleLayout', 'list')
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

        $html = Livewire::test(BarPos::class)
            ->call('setArticleLayout', 'large')
            ->html();

        // Category tiles, not the compact chips: Todo + each category as big tiles.
        $this->assertStringContainsString('data-category-tiles', $html);
        $this->assertStringContainsString('data-category-tile', $html);
        $this->assertStringContainsString(__('Todo'), $html);
        $this->assertStringContainsString('Bebidas', $html);
        $this->assertStringContainsString('Chuches', $html);

        // Filtering is the chips' semantics exactly (filterCategory) — choosing one shows only its articles.
        $filtered = Livewire::test(BarPos::class)
            ->call('setArticleLayout', 'large')
            ->call('filterCategory', $drinks->id);

        $this->assertStringContainsString('Café', $filtered->html());
        $this->assertStringNotContainsString('Gominolas', $filtered->html());

        // Todo (filterCategory(null)) shows every article again.
        $all = $filtered->call('filterCategory', null)->html();
        $this->assertStringContainsString('Café', $all);
        $this->assertStringContainsString('Gominolas', $all);
    }

    public function test_sold_out_is_disabled_and_visible_in_large_mode(): void
    {
        $this->operator();
        $this->article('Agotado', 0);

        $html = Livewire::test(BarPos::class)->call('setArticleLayout', 'large')->html();

        // 230's rule holds at the new size: the sold-out article is shown, with its count, disabled.
        $this->assertStringContainsString('Agotado', $html);            // the article name (visible, not hidden)
        $this->assertStringContainsString('disabled', $html);           // the button carries the disabled attribute
        $this->assertStringContainsString('cursor-not-allowed', $html); // …and the sold-out card styling
        $this->assertStringContainsString('Sold out', $html);           // the count/state is shown (EN locale)
        $this->assertStringContainsString('!min-h-[120px]', $html, 'the sold-out tile is not the large size');
    }

    // --- The choice sticks to the device ----------------------------------------

    public function test_the_choice_persists_across_a_reload(): void
    {
        $this->operator();

        Livewire::test(BarPos::class)->call('setArticleLayout', 'large');

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

    // --- Compact list and grid are byte-identical to before (a toggle, not a change) ---

    public function test_the_compact_cards_are_byte_identical_and_carry_no_large_tokens(): void
    {
        $article = ['id' => 'A1', 'name' => 'Café', 'price_label' => '€2,50', 'stock' => 5, 'low_stock' => false, 'category_name' => 'Bebidas', 'image_url' => null];

        foreach (['list' => 'flex-row items-center gap-3', 'grid' => 'flex-col gap-1'] as $layout => $rootVariant) {
            $html = Blade::render('<x-counter.article-card :article="$article" :layout="$layout" action="addArticle" />', ['article' => $article, 'layout' => $layout]);

            // The exact compact class strings (unchanged from main).
            $this->assertStringContainsString('flex w-full min-h-11 rounded-xl border px-3 py-1.5 text-left transition '.$rootVariant, $html);
            $this->assertStringContainsString('<span data-product-name class="block truncate font-semibold leading-tight">', $html);
            $this->assertStringContainsString('<span class="text-sm font-semibold text-brand tabular-nums dark:text-slate-100">', $html);

            // NONE of the large-only tokens leak into compact.
            foreach (['!min-h-[120px]', '!text-lg', '!text-xl', 'h-24 w-full', '!px-4'] as $largeToken) {
                $this->assertStringNotContainsString($largeToken, $html, "compact $layout leaked a large token: $largeToken");
            }
        }
    }
}
