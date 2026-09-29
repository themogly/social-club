<?php

namespace Tests\Feature\Stock;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 295 (Shane's notes 2 and 3) — the product form has no empty *Categoría*, and the catalogue's create pages go
 * back to their list.
 *
 * Nothing in the app can create an article category (only the demo seeder does), so on a live club the drop-down was
 * always empty. And after *Añadir variedad* the owner landed on the new strain's edit page, not the list they came from.
 */
class CatalogueCreatePagesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    // --- 2. No category -----------------------------------------------------------------------------------------------

    public function test_the_product_form_has_no_category_field(): void
    {
        Livewire::test(CreateArticle::class)->assertFormFieldDoesNotExist('category_id');

        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id]);
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->assertFormFieldDoesNotExist('category_id');
    }

    public function test_with_no_product_categories_the_bar_shows_no_category_chips_or_tiles(): void
    {
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->sede, 'BAR-1', 10000);
        Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'category_id' => null, 'stock' => 5, 'active' => true]);

        foreach (['grid', 'large'] as $layout) {
            session(['counter.bar.article_layout' => $layout]);
            $html = Livewire::test(BarPos::class)->html();

            $this->assertStringNotContainsString('data-category-tile', $html, "{$layout}: an empty category tile row");
            $this->assertStringNotContainsString("filter('category'", $html, "{$layout}: an empty category chip row");
        }
    }

    // --- 3. Back to the list ------------------------------------------------------------------------------------------

    public function test_adding_a_strain_lands_on_the_strains_list_pointing_to_crear_lote(): void
    {
        // Prompt 320 — the strain only; the notification's next step is «Crear lote».
        Livewire::test(CreateGenetic::class)
            ->fillForm(['name' => 'Amnesia Haze', 'product_type' => 'FLOWER'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(GeneticResource::getUrl('index'))
            ->assertNotified(__('Genética creada. Añade existencias con «Crear lote».'));
    }

    public function test_adding_stock_lands_on_the_batches_list(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(CreateBatch::class)
            ->fillForm(['location_id' => $this->sede->id, 'product_type' => $genetic->product_type->value, 'genetic_id' => $genetic->id, 'grams' => '50', 'sale_price_eur' => '8'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(BatchResource::getUrl('index'));
    }

    public function test_a_new_product_lands_on_the_products_list(): void
    {
        Livewire::test(CreateArticle::class)
            ->fillForm(['location_id' => $this->sede->id, 'name' => 'Papers', 'price_eur' => '1', 'stock' => '5'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ArticleResource::getUrl('index'));
    }
}
