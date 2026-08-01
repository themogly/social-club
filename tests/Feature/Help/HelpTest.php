<?php

namespace Tests\Feature\Help;

use App\Enums\Role;
use App\Filament\Pages\Glosario;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Help;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 92 — in-app help. Content only (never gates or changes behaviour), routed through the prompt-19
 * lang pipeline (no parallel store), Spanish authoritative for the terms of art. These walks fail when a
 * new resource ships without an empty state or the shared affordance, and pin that no help string
 * hard-codes a value that is really a Setting.
 */
class HelpTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    /** @return list<class-string<resource>> */
    private function resourceClasses(): array
    {
        $classes = [];
        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) ?: [] as $file) {
            $class = 'App\\'.Str::of($file)->after(app_path().'/')->beforeLast('.php')->replace('/', '\\');
            if (class_exists($class) && is_subclass_of($class, Resource::class) && is_subclass_of($class::getModel(), Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    public function test_every_resource_has_an_empty_state_that_teaches(): void
    {
        foreach ($this->resourceClasses() as $resource) {
            $model = $resource::getModel();
            $copy = Help::emptyStateFor($model);

            $this->assertNotNull($copy, "{$resource} ({$model}) has no empty-state help in Help::EMPTY_STATES — add one.");
            $this->assertNotSame('', trim($copy['heading']));
            $this->assertNotSame('', trim($copy['description']));
        }
    }

    public function test_every_glossary_term_resolves_in_both_locales(): void
    {
        foreach (Help::glossary() as $term => $definition) {
            app()->setLocale('es');
            $es = __($definition);
            app()->setLocale('en');
            $en = __($definition);

            $this->assertNotSame('', trim($es), "Glossary term '{$term}' has no Spanish text.");
            $this->assertNotSame('', trim($en), "Glossary term '{$term}' has no English text.");
            // Translated to English, not left as the Spanish source (defined in one language only).
            $this->assertNotSame($definition, $en, "Glossary term '{$term}' is not translated in en.json.");
        }
    }

    public function test_no_help_string_hard_codes_a_value_that_is_a_setting(): void
    {
        // The rules are configurable Settings; help must NAME them ("the daily limit"), never state a value.
        // (The eighth is a definitional constant — 3,5 g — not a Setting, so it is legitimately stated.)
        $all = implode(' ', array_merge(
            array_merge(...array_map(fn (array $c): array => [$c['heading'], $c['description']], array_values(Help::EMPTY_STATES))),
            array_merge(...array_map(fn (array $t): array => array_merge([$t['title']], $t['body']), array_values(Help::TOPICS))),
            array_values(Help::glossary()),
        ));

        foreach (['18 años', '15 días', '100 g', '10.000', '350 cg'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all, "A help string hard-codes '{$forbidden}', which is a configurable Setting value — name the rule instead.");
        }
    }

    public function test_the_shared_help_affordance_renders_on_a_resource_page(): void
    {
        $this->owner();

        $this->get(MemberResource::getUrl('index'))
            ->assertOk()
            ->assertSee('data-screen-help', false); // the global topbar help menu
    }

    public function test_the_glossary_page_renders_in_both_locales(): void
    {
        $this->owner();

        app()->setLocale('es');
        $this->get(Glosario::getUrl())->assertOk()->assertSee('Aportación')->assertSee('carencia');

        app()->setLocale('en');
        $this->get(Glosario::getUrl())->assertOk()->assertSee('Aportación'); // the term stays Spanish…
    }

    public function test_the_counter_screens_carry_help_in_both_locales(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        CounterOperator::set($user);

        app(ActiveScope::class)->setLocation($this->location->id);

        foreach (['es', 'en'] as $locale) {
            app()->setLocale($locale);
            // The counter help lives in the shared top-bar (the layout), so assert on the full-page render.
            $this->get(route('counter.pos'))->assertOk()->assertSee('data-counter-help', false);
        }
    }
}
