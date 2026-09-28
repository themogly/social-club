<?php

namespace Tests\Feature\Stock;

use App\Actions\Bar\CommitOrder;
use App\Actions\Stock\IntakeArticle;
use App\Actions\Stock\MoveArticleToLocation;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Filament\Resources\Articles\Actions\AddToSedesAction;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 297 — a product's sede can be corrected while it has no history, and a new product can be created at any
 * choice of sedes at once (one product per sede, sharing a `group_id`), with a per-sede choice of where an edit applies.
 */
class ArticleSedesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    /** @var list<Location> */
    private array $sedes = [];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        foreach (['Sede Centro', 'Sede Norte', 'Sede Sur', 'Sede Este', 'Sede Oeste'] as $name) {
            $this->sedes[] = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
        }
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
        app(ActiveScope::class)->setLocation(null);
    }

    private function article(Location $sede, string $name = 'Papers', int $stock = 5): Article
    {
        return (new IntakeArticle)->handle([
            'organisation_id' => $this->org->id, 'location_id' => $sede->id, 'name' => $name, 'price_cents' => 100, 'active' => true,
        ], $stock, ['operator_id' => $this->owner->id]);
    }

    private function sell(Article $article): Order
    {
        $sede = Location::query()->findOrFail($article->location_id);
        $till = (new OpenTill)->handle($sede, 'POS-'.Str::random(4), 10000);

        return (new CommitOrder)->handle($sede, [['article_id' => $article->id, 'qty' => 1]], [
            'operator_id' => $this->owner->id, 'till_session_id' => $till->id, 'cash_cents' => 100, 'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    private function reconciles(Article $article): bool
    {
        $movements = StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $article->id)->get();

        return (int) $movements->sum('qty_units') === $article->fresh()->stock
            && $movements->every(fn (StockMovement $m): bool => $m->location_id === $article->fresh()->location_id);
    }

    /** @return Collection<int, Article> */
    private function articles(string $name): Collection
    {
        return Article::query()->withoutGlobalScopes()->where('name', $name)->get();
    }

    // --- 1. Changing the sede -------------------------------------------------------------------------------------

    public function test_a_product_with_only_its_opening_intake_can_move_sede(): void
    {
        [$centro, $norte] = $this->sedes;
        $papers = $this->article($centro);

        Livewire::test(EditArticle::class, ['record' => $papers->getRouteKey()])
            ->assertFormFieldEnabled('location_id')
            ->fillForm(['location_id' => $norte->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($norte->id, $papers->fresh()->location_id);
        $this->assertTrue($this->reconciles($papers), 'the opening intake stayed behind at the old sede');
        $this->assertSame(5, $papers->fresh()->stock);
        $audit = AuditLog::query()->where('action', 'article.location.changed')->sole();
        $this->assertSame([$centro->id, $norte->id], [$audit->before['location_id'] ?? null, $audit->after['location_id'] ?? null]);
    }

    public function test_after_a_sale_the_sede_is_locked_and_a_forged_move_is_refused(): void
    {
        [$centro, $norte] = $this->sedes;
        $papers = $this->article($centro);
        $this->sell($papers);

        Livewire::test(EditArticle::class, ['record' => $papers->getRouteKey()])
            ->assertFormFieldDisabled('location_id')
            ->assertSee(__('Ya tiene ventas o movimientos de stock en :sede. Para venderlo en otra sede, usa «Añadir a otra sede».', ['sede' => 'Sede Centro']), false)
            ->fillForm(['location_id' => $norte->id])
            ->call('save');

        $this->assertSame($centro->id, $papers->fresh()->location_id);

        $this->expectException(DomainException::class);
        (new MoveArticleToLocation)->handle($papers->fresh(), $norte, $this->owner);
    }

    public function test_a_sale_made_while_the_form_was_open_makes_the_move_refuse(): void
    {
        [$centro, $norte] = $this->sedes;
        $papers = $this->article($centro);

        $page = Livewire::test(EditArticle::class, ['record' => $papers->getRouteKey()])->fillForm(['location_id' => $norte->id]);
        $this->sell($papers); // at the counter, meanwhile
        $page->call('save')->assertHasFormErrors(['location_id']);

        $this->assertSame($centro->id, $papers->fresh()->location_id);
        $this->assertTrue($this->reconciles($papers));
    }

    public function test_a_restock_is_history_too(): void
    {
        [$centro, $norte] = $this->sedes;
        $papers = $this->article($centro);
        (new RecordStockMovement)->handle($papers, StockMovementType::INTAKE, 3, ['operator_id' => $this->owner->id]);

        $this->expectException(DomainException::class);
        (new MoveArticleToLocation)->handle($papers->fresh(), $norte, $this->owner);
    }

    public function test_the_sale_check_reads_the_order_items_as_the_writer_stores_them(): void
    {
        [$centro] = $this->sedes;
        $papers = $this->article($centro);
        $order = $this->sell($papers);

        $raw = (string) DB::table('orders')->where('id', $order->id)->value('items');
        $this->assertStringContainsString('"article_id":"'.$papers->id.'"', str_replace('": "', '":"', $raw));
        $this->assertTrue(Order::query()->withoutGlobalScopes()->containingArticle($papers->id)->exists());
    }

    // --- 4. Añadir a otra sede ------------------------------------------------------------------------------------

    public function test_add_to_another_sede_copies_the_product_with_its_own_stock_and_groups_it(): void
    {
        [$centro, $norte, $sur] = $this->sedes;
        $papers = $this->article($centro);
        $this->article($sur, 'Papers'); // Sur already has one of the same name

        Livewire::test(ListArticles::class)
            ->mountTableAction('addToSedes', $papers)
            ->setTableActionData(['location_id' => [$norte->id], 'stock_at' => [$norte->id => '7']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $copy = Article::query()->withoutGlobalScopes()->where('location_id', $norte->id)->sole();
        $this->assertSame('Papers', $copy->name);
        $this->assertSame(100, $copy->price_cents->cents);
        $this->assertSame(7, $copy->stock);
        $this->assertNotNull($papers->fresh()->group_id);
        $this->assertSame($papers->fresh()->group_id, $copy->group_id);
        $this->assertTrue($this->reconciles($copy));

        $this->assertArrayNotHasKey($sur->id, AddToSedesAction::sedeOptions($papers->fresh()));
        $this->assertArrayNotHasKey($norte->id, AddToSedesAction::sedeOptions($papers->fresh()));
    }

    // --- 5–7. Choosing sedes on create --------------------------------------------------------------------------------

    public function test_ticking_three_of_five_sedes_creates_three_grouped_products_with_their_own_stock(): void
    {
        [$centro, $norte, $sur] = $this->sedes;

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'location_id' => [$centro->id, $norte->id, $sur->id],
                'name' => 'Mechero', 'price_eur' => '1.50',
                'stock_at' => [$centro->id => '10', $norte->id => '4', $sur->id => '0'],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('Producto creado en :count sedes', ['count' => 3]));

        $mecheros = $this->articles('Mechero');
        $this->assertCount(3, $mecheros);
        $this->assertEqualsCanonicalizing([$centro->id, $norte->id, $sur->id], $mecheros->pluck('location_id')->all());
        $this->assertSame(1, $mecheros->pluck('group_id')->unique()->count());
        $this->assertNotNull($mecheros->first()->group_id);
        $this->assertSame([10, 4, 0], [$mecheros->firstWhere('location_id', $centro->id)->stock, $mecheros->firstWhere('location_id', $norte->id)->stock, $mecheros->firstWhere('location_id', $sur->id)->stock]);
        $this->assertSame(150, $mecheros->first()->price_cents->cents);
    }

    public function test_creating_at_several_sedes_is_all_or_nothing(): void
    {
        [$centro, $norte] = $this->sedes;
        Article::creating(function (Article $article) use ($norte): void {
            if ($article->location_id === $norte->id) {
                throw new \RuntimeException('disk full');
            }
        });

        try {
            Livewire::test(CreateArticle::class)
                ->fillForm(['location_id' => [$centro->id, $norte->id], 'name' => 'Mechero', 'price_eur' => '1', 'stock_at' => [$centro->id => '3', $norte->id => '3']])
                ->call('create');
        } catch (\RuntimeException) {
        }

        $this->assertCount(0, $this->articles('Mechero'));
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_all_sedes_selects_every_reachable_sede_and_unticks_itself(): void
    {
        $ids = collect($this->sedes)->pluck('id')->all();

        $page = Livewire::test(CreateArticle::class)
            ->assertSee(__('Todas las sedes'))
            ->set('data.location_id', ['all']);
        $this->assertEqualsCanonicalizing(['all', ...$ids], $page->get('data.location_id'));

        $page->set('data.location_id', array_values(array_diff(['all', ...$ids], [$ids[4]])));
        $this->assertEqualsCanonicalizing(array_slice($ids, 0, 4), $page->get('data.location_id'), 'Todas stayed ticked with a sede unticked');

        $page->set('data.location_id', ['all', ...array_slice($ids, 0, 4)])->fillForm(['name' => 'Papel', 'price_eur' => '1'])->call('create')->assertHasNoFormErrors();
        $this->assertCount(5, $this->articles('Papel'));
    }

    public function test_a_manager_gets_your_sedes_covering_only_theirs(): void
    {
        [$centro, $norte, $sur] = $this->sedes;
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$centro->id, $norte->id]);
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($centro->id);

        $page = Livewire::test(CreateArticle::class)->assertSee(__('Tus sedes'))->set('data.location_id', ['all']);
        $this->assertEqualsCanonicalizing(['all', $centro->id, $norte->id], $page->get('data.location_id'));

        $page->set('data.location_id', [$sur->id])->fillForm(['name' => 'Ajeno', 'price_eur' => '1'])->call('create')->assertHasFormErrors(['location_id']);
        $this->assertCount(0, $this->articles('Ajeno'));
    }

    public function test_with_a_single_sede_the_field_is_the_single_locked_one(): void
    {
        foreach (array_slice($this->sedes, 1) as $sede) {
            $sede->forceDelete();
        }
        app(ActiveScope::class)->setLocation($this->sedes[0]->id);

        Livewire::test(CreateArticle::class)
            ->assertFormFieldDisabled('location_id')
            ->assertDontSee(__('Todas las sedes'))
            ->assertSchemaStateSet(['location_id' => $this->sedes[0]->id]);
    }

    public function test_the_opening_stock_inputs_follow_the_selection(): void
    {
        [$centro, $norte] = $this->sedes;

        Livewire::test(CreateArticle::class)
            ->fillForm(['location_id' => [$centro->id]])
            ->assertFormFieldExists('stock')
            ->assertFormFieldDoesNotExist("stock_at.{$centro->id}")
            ->set('data.location_id', [$centro->id, $norte->id])
            ->assertSet("data.stock_at.{$norte->id}", 0) // a sede just ticked shows 0, not an empty box
            ->assertFormFieldDoesNotExist('stock')
            ->assertFormFieldExists("stock_at.{$centro->id}")
            ->assertFormFieldExists("stock_at.{$norte->id}")
            ->assertFormFieldDoesNotExist("stock_at.{$this->sedes[2]->id}");
    }

    public function test_the_top_bar_sede_is_the_default_and_the_rollup_defaults_to_nothing(): void
    {
        Livewire::test(CreateArticle::class)->assertSchemaStateSet(['location_id' => []]);

        app(ActiveScope::class)->setLocation($this->sedes[1]->id);
        Livewire::test(CreateArticle::class)->assertSchemaStateSet(['location_id' => [$this->sedes[1]->id]]);
    }

    // --- 8. Applying edits to chosen sedes ------------------------------------------------------------------------

    public function test_an_edit_applies_to_this_product_and_the_ticked_sedes_only(): void
    {
        Livewire::test(CreateArticle::class)
            ->set('data.location_id', ['all'])
            ->fillForm(['name' => 'Mechero', 'price_eur' => '1'])
            ->fillForm(['stock_at' => collect($this->sedes)->mapWithKeys(fn (Location $s, int $i): array => [$s->id => (string) ($i + 1)])->all()])
            ->call('create')->assertHasNoFormErrors();
        $group = $this->articles('Mechero')->keyBy('location_id');
        [$centro, $norte, $sur, $este, $oeste] = $this->sedes;
        $here = $group[$centro->id];

        Livewire::test(EditArticle::class, ['record' => $here->getRouteKey()])
            ->assertSchemaStateSet(['apply_to' => []])
            ->fillForm(['name' => 'Mechero Clipper', 'price_eur' => '2.50', 'low_stock_threshold' => '2', 'active' => false, 'apply_to' => [$group[$norte->id]->id, $group[$sur->id]->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        foreach ([$centro, $norte, $sur] as $sede) {
            $a = $group[$sede->id]->fresh();
            $this->assertSame(['Mechero Clipper', 250, 2, false], [$a->name, $a->price_cents->cents, $a->low_stock_threshold, $a->active], $sede->name);
        }
        foreach ([$este, $oeste] as $sede) {
            $a = $group[$sede->id]->fresh();
            $this->assertSame(['Mechero', 100, true], [$a->name, $a->price_cents->cents, $a->active], $sede->name.' changed without being ticked');
        }
        $this->assertSame([1, 2, 3, 4, 5], collect($this->sedes)->map(fn (Location $s): int => $group[$s->id]->fresh()->stock)->all(), 'stock is never shared');
        $this->assertSame(1, AuditLog::query()->where('action', 'article.group.updated')->count());
    }

    public function test_with_nothing_ticked_only_this_product_changes_and_todas_ticks_all_the_others(): void
    {
        Livewire::test(CreateArticle::class)
            ->set('data.location_id', ['all'])
            ->fillForm(['name' => 'Mechero', 'price_eur' => '1'])
            ->call('create')->assertHasNoFormErrors();
        $group = $this->articles('Mechero')->keyBy('location_id');
        $here = $group[$this->sedes[0]->id];
        $others = $group->reject(fn (Article $a): bool => $a->is($here))->pluck('id')->all();

        Livewire::test(EditArticle::class, ['record' => $here->getRouteKey()])
            ->fillForm(['price_eur' => '3'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(300, $here->fresh()->price_cents->cents);
        $this->assertSame([100], $group->reject(fn (Article $a): bool => $a->is($here))->map(fn (Article $a): int => $a->fresh()->price_cents->cents)->unique()->values()->all());

        $page = Livewire::test(EditArticle::class, ['record' => $here->getRouteKey()])->set('data.apply_to', ['all']);
        $this->assertEqualsCanonicalizing(['all', ...$others], $page->get('data.apply_to'));
    }

    public function test_a_manager_never_reaches_another_managers_sede_through_the_group(): void
    {
        Livewire::test(CreateArticle::class)
            ->set('data.location_id', ['all'])
            ->fillForm(['name' => 'Mechero', 'price_eur' => '1'])
            ->call('create')->assertHasNoFormErrors();
        $group = $this->articles('Mechero')->keyBy('location_id');
        [$centro, $norte, $sur] = $this->sedes;
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$centro->id, $norte->id]);
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($centro->id);

        Livewire::test(EditArticle::class, ['record' => $group[$centro->id]->getRouteKey()])
            ->assertSee('Sede Norte')
            ->assertDontSee('Sede Sur')
            ->fillForm(['price_eur' => '9', 'apply_to' => [$group[$sur->id]->id]])
            ->call('save');

        $this->assertSame(100, $group[$sur->id]->fresh()->price_cents->cents);
    }

    // --- 9. Existing data -----------------------------------------------------------------------------------------

    public function test_existing_products_have_no_group_and_edit_as_before(): void
    {
        $papers = $this->article($this->sedes[0]);
        $this->assertNull($papers->group_id);

        Livewire::test(EditArticle::class, ['record' => $papers->getRouteKey()])
            ->assertFormFieldHidden('apply_to')
            ->fillForm(['price_eur' => '2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(200, $papers->fresh()->price_cents->cents);
        $this->assertNull($papers->fresh()->group_id);
    }
}
