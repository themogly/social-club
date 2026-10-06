<?php

namespace Tests\Feature\Panel;

use App\Enums\Role;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Dispensations\Pages\ListDispensations;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\Article;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prompt 354 — Ben, from his iPhone: the panel's list screens. Row actions collapse into one ⋮ (inline buttons were
 * pinned to the right edge and covered the row — Applications' 457 px covered every column); every list is newest first;
 * and the Members column after the number said "Alta: Completa" — it is the membership, not the join date.
 */
class ListScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'created_at' => now()->subYears(2)]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['created_at' => now()->subYears(2)]);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    /** @return array<class-string<resource>, class-string> every resource with a list screen => its list page */
    private function listPages(): array
    {
        $pages = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $index = $resource::getPages()['index'] ?? null;
            if ($index !== null) {
                $pages[$resource] = $index->getPage();
            }
        }

        return $pages;
    }

    private function table(Testable $component): Table
    {
        $instance = $component->instance();
        $this->assertInstanceOf(HasTable::class, $instance);

        return $instance->getTable();
    }

    // --- 1–2. Row actions: one ⋮, never inline buttons ------------------------------------------------------------------

    public function test_every_list_screens_row_actions_are_one_group(): void
    {
        $pages = $this->listPages();
        $this->assertCount(26, $pages, 'the sweep should cover all 26 list screens');

        $inline = [];
        foreach ($pages as $resource => $page) {
            $actions = array_values($this->table(Livewire::test($page))->getRecordActions());
            if ($actions !== [] && (count($actions) !== 1 || ! $actions[0] instanceof ActionGroup)) {
                $inline[] = class_basename($resource).' ('.count($actions).' inline)';
            }
        }

        $this->assertSame([], $inline, 'row actions must be ONE ActionGroup (⋮) — an inline button is pinned over the row on a phone');
    }

    // --- 3. The row opens the record ---------------------------------------------------------------------------------------

    public function test_a_row_click_opens_the_record_on_applications_articles_and_dispensations(): void
    {
        $application = MemberApplication::factory()->create(['organisation_id' => $this->org->id]);
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id]);
        $dispensation = Dispensation::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id]);

        foreach ([ListMemberApplications::class => $application, ListArticles::class => $article, ListDispensations::class => $dispensation] as $page => $record) {
            $url = $this->table(Livewire::test($page))->getRecordUrl($record);
            $this->assertNotNull($url, class_basename($page).': the row opens nothing');
            $this->assertStringContainsString((string) $record->getRouteKey(), (string) $url);
        }
    }

    // --- 4. Newest first ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: class-string<Model>, 1: string, 2: array<string, mixed>}> */
    public static function newestFirst(): array
    {
        return [
            'members' => [Member::class, 'members', []],
            'applications' => [MemberApplication::class, 'member-applications', []],
            'articles' => [Article::class, 'articles', ['location_id' => true]],
            'genetics' => [Genetic::class, 'genetics', []],
            'discounts' => [Discount::class, 'discounts', []],
            'expense categories' => [ExpenseCategory::class, 'expense-categories', []],
            'sedes' => [Location::class, 'locations', []],
            'tiers' => [MembershipTier::class, 'membership-tiers', []],
            'users' => [User::class, 'users', []],
            'suppliers' => [Supplier::class, 'suppliers', []],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('newestFirst')]
    public function test_the_newer_row_is_first_on_a_fresh_session(string $model, string $slug, array $extra): void
    {
        $attributes = fn (string $when, string $name): array => array_filter([
            'organisation_id' => in_array('organisation_id', (new $model)->getFillable(), true) || $model !== User::class ? $this->org->id : null,
            'location_id' => isset($extra['location_id']) ? $this->sede->id : null,
            'created_at' => $when,
            'joined_at' => $model === Member::class ? $when : null,
            'name' => in_array($model, [Member::class, MemberApplication::class], true) ? null : $name,
        ], fn ($v): bool => $v !== null);

        // The OLDER row has the alphabetically FIRST name, so an A–Z or database order would put it on top.
        $older = $model::factory()->create($attributes(now()->subDays(2)->toDateTimeString(), 'Aaa más antiguo'));
        $newer = $model::factory()->create($attributes(now()->subDay()->toDateTimeString(), 'Zzz más nuevo'));
        if ($model === User::class) {
            $older->locations()->sync([$this->sede->id]);
            $newer->locations()->sync([$this->sede->id]);
        }

        $page = collect($this->listPages())->first(fn (string $page, string $resource): bool => $resource::getSlug() === $slug);
        $this->assertNotNull($page, "no list page for {$slug}");
        $records = $this->table(Livewire::test($page))->getLivewire()->getTableRecords()->values();
        $positions = $records->map(fn (Model $r): string => (string) $r->getKey())->flip();

        $this->assertTrue($positions->has((string) $newer->getKey()) && $positions->has((string) $older->getKey()), "{$slug}: both rows should be listed");
        $this->assertLessThan($positions[(string) $older->getKey()], $positions[(string) $newer->getKey()], "{$slug}: the newer row must come first");
    }

    // --- 5. Members: one "Alta", and it is the date ------------------------------------------------------------------------

    public function test_members_has_one_alta_column_and_it_is_the_join_date_and_the_badge_is_membresia(): void
    {
        $active = Member::factory()->create(['organisation_id' => $this->org->id]);
        $table = $this->table(Livewire::test(ListMembers::class));
        $columns = collect($table->getColumns());

        $alta = $columns->filter(fn (Column $c): bool => $c->getLabel() === __('Alta'));
        $this->assertSame(['joined_at'], $alta->map(fn (Column $c): string => $c->getName())->values()->all());

        $badge = $table->getColumn('membership_gap');
        $this->assertSame(__('Membresía'), $badge->getLabel());
        $badge->record($active);
        $this->assertContains($badge->getState(), [__('Activa'), __('Sin membresía')]);
        foreach (['es', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame(__('Alta'), __('Membresía'), $locale);
        }
    }

    public function test_no_list_screen_has_two_columns_with_one_label(): void
    {
        $duplicates = [];
        foreach ($this->listPages() as $resource => $page) {
            $labels = collect($this->table(Livewire::test($page))->getColumns())->map(fn (Column $c): string => (string) $c->getLabel());
            foreach ($labels->duplicates()->unique() as $label) {
                $duplicates[] = class_basename($resource).': «'.$label.'»';
            }
        }

        $this->assertSame([], $duplicates);
    }
}
