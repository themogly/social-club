<?php

namespace Tests\Feature\Help;

use App\Actions\Pricing\ResolvePrice;
use App\Enums\Role;
use App\Filament\Pages\Glosario;
use App\Filament\Pages\ManageEnforcement;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\Manual;
use App\Filament\Pages\Rat;
use App\Models\AuditLog;
use App\Models\DataRequest;
use App\Models\Dispensation;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Help;
use App\Support\LangKeys;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * Prompt 99 — the help must cover EVERY screen, match the reader's ROLE, and teach the tasks people do.
 * These walks fail the moment a resource or page ships without a topic, and pin that the role filtering
 * runs BOTH ways (staff see the counter help, never the settings/RGPD help; the owner sees all) and that
 * the eighth-pricing worked example is exactly what ResolvePrice charges — never a hand-typed number.
 */
class HelpGuidesTest extends TestCase
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

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
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

    /** Every concrete admin page — minus the abstract report base and the help pages themselves. @return list<class-string> */
    private function pageClasses(): array
    {
        $exclude = [Glosario::class, Manual::class];
        $classes = [];
        foreach (array_merge(
            glob(app_path('Filament/Pages/*.php')) ?: [],
            glob(app_path('Filament/Pages/Reports/*.php')) ?: [],
        ) as $file) {
            $class = 'App\\'.Str::of($file)->after(app_path().'/')->beforeLast('.php')->replace('/', '\\');
            if (! class_exists($class) || ! is_subclass_of($class, Page::class)) {
                continue;
            }
            if ((new ReflectionClass($class))->isAbstract() || in_array($class, $exclude, true)) {
                continue;
            }
            $classes[] = $class;
        }

        return $classes;
    }

    /** @return list<string> */
    private function allHelpStrings(): array
    {
        $strings = [];
        foreach (Help::allTopics() as $topic) {
            $strings[] = $topic['title'];
            array_push($strings, ...$topic['body']);
        }
        foreach (Help::GUIDES as $guide) {
            $strings[] = $guide['title'];
            $strings[] = $guide['intro'];
            foreach ($guide['steps'] as $step) {
                $strings[] = $step['title'];
                array_push($strings, ...$step['body']);
            }
        }

        return $strings;
    }

    public function test_every_resource_has_a_help_topic(): void
    {
        foreach ($this->resourceClasses() as $resource) {
            $model = $resource::getModel();
            $this->assertNotNull(Help::topicFor($model), "{$resource} ({$model}) has no help topic — add one to Help::TOPICS.");
        }
    }

    public function test_every_admin_page_has_a_help_topic(): void
    {
        $pages = $this->pageClasses();
        $this->assertNotEmpty($pages);
        foreach ($pages as $page) {
            $this->assertNotNull(Help::topicFor($page), "{$page} has no help topic — add one to Help::PAGE_TOPICS.");
        }
    }

    public function test_staff_see_the_counter_help_but_never_the_settings_or_rgpd_help(): void
    {
        $topics = Help::topicsVisibleTo($this->userWithRole(Role::STAFF));

        // Sees the screens a member of staff actually works on.
        $this->assertArrayHasKey(Member::class, $topics);
        $this->assertArrayHasKey(Dispensation::class, $topics);
        $this->assertArrayHasKey(Order::class, $topics);
        $this->assertArrayHasKey(TillSession::class, $topics);
        $this->assertArrayHasKey(Expense::class, $topics);

        // Never sees the compliance / privacy / staff-management screens.
        $this->assertArrayNotHasKey(ManageSettings::class, $topics);
        $this->assertArrayNotHasKey(ManageEnforcement::class, $topics);
        $this->assertArrayNotHasKey(Rat::class, $topics);
        $this->assertArrayNotHasKey(DataRequest::class, $topics);
        $this->assertArrayNotHasKey(AuditLog::class, $topics);
        $this->assertArrayNotHasKey(User::class, $topics);
    }

    public function test_the_owner_sees_every_topic(): void
    {
        $topics = Help::topicsVisibleTo($this->userWithRole(Role::OWNER));

        $this->assertCount(count(Help::allTopics()), $topics);
    }

    public function test_staff_only_see_the_guides_they_can_start(): void
    {
        $guides = Help::guidesVisibleTo($this->userWithRole(Role::STAFF));

        // Can start the counter / till tasks.
        $this->assertArrayHasKey('taking-payment', $guides);
        $this->assertArrayHasKey('till-day', $guides);
        $this->assertArrayHasKey('eighth-pricing', $guides);

        // Cannot start the setup / catalogue tasks (no locations.manage / genetics.manage).
        $this->assertArrayNotHasKey('new-location', $guides);
        $this->assertArrayNotHasKey('add-product', $guides);
    }

    public function test_the_owner_sees_every_guide(): void
    {
        $this->assertCount(count(Help::GUIDES), Help::guidesVisibleTo($this->userWithRole(Role::OWNER)));
    }

    public function test_no_guide_is_ever_shown_to_someone_who_cannot_perform_its_first_step(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $user = $this->userWithRole($role);

            // Every visible guide: the reader CAN perform its first step.
            foreach (Help::guidesVisibleTo($user) as $key => $guide) {
                $permission = $guide['permission'];
                $this->assertTrue(
                    $permission === null || $user->can($permission),
                    "Guide '{$key}' shown to {$role->value} who lacks its first-step permission '{$permission}'."
                );
            }

            // The converse: a guide whose first step the reader cannot perform is hidden.
            foreach (Help::GUIDES as $key => $guide) {
                if ($guide['permission'] !== null && ! $user->can($guide['permission'])) {
                    $this->assertArrayNotHasKey($key, Help::guidesVisibleTo($user), "Guide '{$key}' leaked to {$role->value}.");
                }
            }
        }
    }

    public function test_every_topic_and_guide_string_is_translated_in_both_locales(): void
    {
        $en = LangKeys::fileMap('en');
        $es = LangKeys::fileMap('es');

        foreach ($this->allHelpStrings() as $string) {
            $this->assertArrayHasKey($string, $es, "Missing Spanish key: {$string}");
            // A key missing from en.json would leak Spanish into the English UI.
            $this->assertArrayHasKey($string, $en, "Missing English key (would leak Spanish): {$string}");
        }
    }

    public function test_the_eighth_guide_example_is_exactly_what_resolveprice_charges(): void
    {
        // Reproduce the counter arithmetic independently from the RAW inputs (member discount → the
        // post-prompt-90 eighth break), then assert the guide shows precisely that — pinned to the code.
        $e = Help::EIGHTH_EXAMPLE;
        $effPerGram = $e['base_per_gram_cents'] - (int) round_half_up($e['base_per_gram_cents'] * $e['discount_bp'] / 10_000);
        $effEighth = $e['base_eighth_cents'] - (int) round_half_up($e['base_eighth_cents'] * $e['discount_bp'] / 10_000);
        $perGramTotal = (int) round_half_up($effPerGram * $e['grams_cg'] / 100);
        $charged = app(ResolvePrice::class)->applyEighthBreaks([[
            'grams_cg' => $e['grams_cg'],
            'rate_cents' => $effPerGram,
            'per_gram_total' => $perGramTotal,
            'eighth_price' => $effEighth,
        ]])[0]['total_cents'];

        $shown = Help::eighthExample();

        $this->assertSame($charged, $shown['charged_cents'], 'The eighth guide must show exactly what ResolvePrice charges.');
        $this->assertSame($perGramTotal, $shown['per_gram_total_cents']);
        $this->assertSame($effPerGram, $shown['eff_per_gram_cents']);
        $this->assertSame($effEighth, $shown['eff_eighth_cents']);
        // The break must genuinely be cheaper — that is the whole point of the worked example.
        $this->assertLessThan($shown['per_gram_total_cents'], $shown['charged_cents']);
        $this->assertSame($shown['per_gram_total_cents'] - $shown['charged_cents'], $shown['saving_cents']);
        $this->assertGreaterThan(0, $shown['saving_cents']);
    }

    public function test_the_manual_renders_for_owner_and_staff_in_both_locales(): void
    {
        foreach ([Role::OWNER, Role::STAFF] as $role) {
            $this->userWithRole($role);
            foreach (['es', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->get(Manual::getUrl())->assertOk();
            }
        }
    }

    public function test_the_manual_shows_staff_the_counter_guide_but_hides_the_setup_guide(): void
    {
        $this->userWithRole(Role::STAFF);
        app()->setLocale('es');

        $response = $this->get(Manual::getUrl())->assertOk();
        $response->assertSee(__('Cobrar una aportación'));       // a guide staff can start
        $response->assertDontSee(__('Abrir una sede nueva'));    // one they cannot (locations.manage)
        $response->assertDontSee(__('Ajustes de la organización')); // a settings topic they cannot reach
    }
}
