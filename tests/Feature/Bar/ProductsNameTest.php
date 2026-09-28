<?php

namespace Tests\Feature\Bar;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Filament\Resources\Articles\ArticleResource;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 295 (Shane's note 1) — what the bar and the shop sell is a *producto* (*product*), not an *artículo*.
 *
 * Shane's choice, over *Carta* (it clashes with the member area's menu) and *Productos de barra* (it hides the shop).
 * The words change, never the code: the `Article` model, the `articles` table, the routes and `articles.manage` stay.
 * A legal article — "Artículo 30 RGPD" — is still an article.
 */
class ProductsNameTest extends TestCase
{
    use RefreshDatabase;

    /** A legal article, which keeps its word: "Artículo 30 RGPD", "Article 33 GDPR". */
    private const LEGAL = '/\b(art[ií]culo|article)\s+\d+/iu';

    public function test_the_panel_calls_them_productos(): void
    {
        $this->assertSame(__('Productos'), ArticleResource::getNavigationLabel());
        $this->assertSame(__('producto'), ArticleResource::getModelLabel());
        $this->assertSame(__('productos'), ArticleResource::getPluralModelLabel());

        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $this->assertSame('Products', $en['Productos'] ?? null);
        $this->assertSame('product', $en['producto'] ?? null);
        $this->assertSame('Search product…', $en['Buscar producto…'] ?? null);
    }

    public function test_no_string_calls_a_bar_item_an_article_in_either_language(): void
    {
        $es = json_decode((string) file_get_contents(lang_path('es.json')), true);
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);

        $spanish = array_filter(array_keys($es), fn (string $key): bool => preg_match('/art[ií]culos?\b/iu', (string) preg_replace(self::LEGAL, '', $key)) === 1);
        $english = array_filter($en, fn (string $value): bool => preg_match('/\barticles?\b/i', (string) preg_replace(self::LEGAL, '', $value)) === 1);

        $this->assertSame([], array_values($spanish), 'a Spanish string still says artículo for a bar/shop item');
        $this->assertSame([], $english, 'an English string still says article for a bar/shop item');
    }

    public function test_the_counter_bar_searches_productos(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($location->id);
        session(['counter.location_id' => $location->id]);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$location->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        (new OpenTill)->handle($location, 'BAR-1', 10000);
        Article::factory()->create(['organisation_id' => $org->id, 'location_id' => $location->id, 'name' => 'Agua', 'stock' => 5, 'active' => true]);

        $html = Livewire::test(BarPos::class)->html();

        $this->assertStringContainsString(e(__('Buscar producto…')), $html);
        $this->assertStringContainsString(e(__('Productos')), $html);
        $this->assertDoesNotMatchRegularExpression('/art[ií]culo/iu', strip_tags((string) preg_replace('/<!--.*?-->/s', '', $html)), 'the bar screen still says artículo');
    }
}
