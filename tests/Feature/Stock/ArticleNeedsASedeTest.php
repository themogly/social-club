<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Models\Article;
use App\Models\CheckIn;
use App\Models\Concerns\ScopedToLocation;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 294 — creating a bar/shop article crashed in the "Todas las sedes" view (Sentry, production, 28 Sept):
 * `location_id cannot be null`. The article form never asked for a sede, so `ScopedToLocation` filled it from the active
 * scope — null in the rollup, which PIN sign-in (267/270) and 284 made the normal place for a multi-sede owner to be.
 * Batches had the same gap and prompt 238 gave them a Sede field; articles now name their sede the same way, and a
 * model-level guard stops the next model that forgets one from reaching the database.
 */
class ArticleNeedsASedeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($this->owner);
    }

    /** @return array<string, mixed> */
    private function papers(array $overrides = []): array
    {
        return array_merge(['name' => 'Papers', 'price_eur' => '1', 'stock' => '5', 'low_stock_threshold' => '30'], $overrides);
    }

    // 1 -------------------------------------------------------------------------------------------------------------

    public function test_in_the_rollup_a_missing_sede_is_a_form_error_not_a_crash(): void
    {
        app(ActiveScope::class)->setLocation(null);

        Livewire::test(CreateArticle::class)
            ->fillForm($this->papers())
            ->call('create')
            ->assertHasFormErrors(['location_id' => 'required']);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    // 2 -------------------------------------------------------------------------------------------------------------

    public function test_in_the_rollup_the_chosen_sede_gets_the_article_and_its_opening_stock(): void
    {
        app(ActiveScope::class)->setLocation(null);

        Livewire::test(CreateArticle::class)
            ->fillForm($this->papers(['location_id' => [$this->norte->id]])) // prompt 297: a choice of sedes
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->norte->id, $article->location_id);
        $this->assertSame(5, $article->stock);
        $movement = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)->sole();
        $this->assertSame(StockMovementType::INTAKE, $movement->type);
        $this->assertSame($this->norte->id, $movement->location_id);
    }

    // 3 -------------------------------------------------------------------------------------------------------------

    public function test_the_sede_in_the_top_bar_is_the_default(): void
    {
        app(ActiveScope::class)->setLocation($this->centro->id);

        Livewire::test(CreateArticle::class)->assertSchemaStateSet(['location_id' => [$this->centro->id]]);
    }

    // 4 -------------------------------------------------------------------------------------------------------------

    public function test_with_one_sede_the_field_is_locked_prefilled_and_still_submitted(): void
    {
        $this->norte->forceDelete();
        app(ActiveScope::class)->setLocation($this->centro->id);

        Livewire::test(CreateArticle::class)
            ->assertFormFieldDisabled('location_id')
            ->fillForm($this->papers())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->centro->id, Article::query()->withoutGlobalScopes()->sole()->location_id);
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_the_sede_is_fixed_once_the_article_has_history(): void
    {
        // Prompt 297 — correctable until the product has a past (297's own tests cover the move); then fixed.
        app(ActiveScope::class)->setLocation($this->centro->id);
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id]);
        (new RecordStockMovement)->handle($article, StockMovementType::ADJUSTMENT, 2, ['reason' => 'recuento']);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->assertFormFieldDisabled('location_id');
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    public function test_the_store_is_not_offered(): void
    {
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        app(ActiveScope::class)->setLocation(null);

        Livewire::test(CreateArticle::class)
            ->fillForm($this->papers(['location_id' => [$store->id]]))
            ->call('create')
            ->assertHasFormErrors(['location_id']);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    // 7 -------------------------------------------------------------------------------------------------------------

    public function test_a_scoped_model_with_no_sede_is_refused_before_the_database(): void
    {
        app(ActiveScope::class)->setLocation(null);

        $this->expectException(DomainException::class);
        CheckIn::query()->create(['organisation_id' => $this->org->id, 'checked_in_at' => now()]);
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    public function test_every_create_page_for_a_location_scoped_model_asks_for_the_sede(): void
    {
        app(ActiveScope::class)->setLocation(null);
        $checked = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $model = $resource::getModel();
            if (! in_array(ScopedToLocation::class, class_uses_recursive($model), true) || ! $resource::hasPage('create')) {
                continue;
            }

            $page = $resource::getPages()['create']->getPage();
            Livewire::test($page)->assertFormFieldExists('location_id');
            $checked[] = class_basename($model);
        }

        sort($checked);
        $this->assertSame(['Article', 'Batch'], $checked, 'A location-scoped model gained a create page: give its form a Sede field.');
    }
}
