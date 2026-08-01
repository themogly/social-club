<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\IntakeArticle;
use App\Actions\Stock\RecordStockMovement;
use App\Console\Commands\ReconcileArticleStock;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\Category;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 104 — bar/shop article opening stock must enter the LEDGER as an INTAKE (like a batch), so every
 * article reconciles (Σ qty_units movements == stock) instead of the ledger holding only depletions and
 * summing negative. Restock is an INTAKE, not an ADJUSTMENT (which would file every delivery as a correction).
 */
class ArticleOpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->category = Category::factory()->create(['organisation_id' => $this->org->id]);
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'name' => 'Agua',
            'category_id' => $this->category->id, 'price_cents' => 150, 'low_stock_threshold' => 10, 'active' => true,
        ];
    }

    private function ledger(Article $article): int
    {
        return (int) StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_type', $article->getMorphClass())->where('stockable_id', $article->id)->sum('qty_units');
    }

    public function test_creating_an_article_writes_exactly_one_intake_for_the_opening_stock(): void
    {
        $article = (new IntakeArticle)->handle($this->attributes(), 24);

        $this->assertSame(24, $article->stock);
        $movements = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)->get();
        $this->assertCount(1, $movements);
        $this->assertSame(StockMovementType::INTAKE, $movements->first()->type);
        $this->assertSame(24, (int) $movements->first()->qty_units);
        $this->assertSame($article->stock, $this->ledger($article)); // the invariant, from the first row
    }

    public function test_zero_opening_stock_writes_no_spurious_movement(): void
    {
        $article = (new IntakeArticle)->handle($this->attributes(), 0);

        $this->assertSame(0, $article->stock);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)->count());
    }

    public function test_restock_records_an_intake_not_an_adjustment(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value); // articles.manage
        $manager->locations()->sync([$this->location->id]);
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($this->location->id);

        $article = (new IntakeArticle)->handle($this->attributes(), 10);

        Livewire::test(ListArticles::class)->callTableAction('restock', $article, ['units' => 5]);

        $article->refresh();
        $this->assertSame(15, $article->stock);
        $this->assertSame(2, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)
            ->where('type', StockMovementType::INTAKE->value)->count()); // opening + restock, both INTAKE
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)
            ->where('type', StockMovementType::ADJUSTMENT->value)->count());
        $this->assertSame($article->stock, $this->ledger($article));
    }

    public function test_the_backfill_reconciles_a_pre_existing_article_and_is_safe_twice(): void
    {
        // A pre-existing article written the OLD way — stock set directly, only a sale in the ledger.
        $article = Article::create($this->attributes() + ['stock' => 20]);
        (new RecordStockMovement)->handle($article, StockMovementType::SALE, -3); // stock → 17, ledger → -3
        $article->refresh();
        $this->assertNotSame($article->stock, $this->ledger($article)); // broken today

        $this->artisan('csc:reconcile-article-stock')->assertSuccessful();

        $article->refresh();
        $this->assertSame($article->stock, $this->ledger($article)); // reconciles
        $backfill = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)
            ->where('reason', ReconcileArticleStock::REASON);
        $this->assertSame(1, (clone $backfill)->count()); // one identifiable reconciliation row

        // Running again is a no-op — no double-fill.
        $this->artisan('csc:reconcile-article-stock')->assertSuccessful();
        $this->assertSame(1, (clone $backfill)->count());
    }
}
