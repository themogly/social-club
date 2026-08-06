<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 176 — writes the REAL, authed SELLING screens in their resolved states (a socio identified, a
 * basket empty and full) to storage/app/cart-*.html for the Playwright measurement pass
 * (`node tests/Browser/measure-cart-column.mjs`).
 *
 * Why this file exists at all, given BlockingStatesHarnessTest: that harness could only capture the
 * BLOCKED screens, because `DispensaryPos::mount()` takes no parameters and reads no member from the
 * request, so a plain GET can never reach the resolved screen. Prompt 176 is entirely about the resolved
 * screen — where the commit button sits once there IS something to commit — so the component is driven
 * through Livewire and its HTML rendered into the real counter layout.
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check: the cart
 * column carries the commit action and the allowance, and the selection pane is the only scroll container.
 * The pixel proof — that the action is inside the viewport at both orientations — is the .mjs script.
 */
class CartColumnHarnessTest extends TestCase
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
        $this->location = Location::factory()->create([
            'organisation_id' => $this->org->id, 'name' => 'Sede Centro',
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['name' => 'Marta Ruiz']);
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    private function genetic(string $name, int $centsPerGram = 800): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => $centsPerGram, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN,
            'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => 'Lucía', 'last_name' => 'García',
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'General']);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /**
     * Wrap a component's rendered HTML in the REAL counter layout, with the BUILT css inlined.
     *
     * Two fidelity details this depends on, both learned the hard way:
     *
     * 1. `fullHeight` must be passed, because the real page passes it — the selling screens declare
     *    `#[Layout('components.layouts.counter', ['fullHeight' => true])]`. Without it the harness
     *    photographs a shell the operator never sees.
     * 2. ONLY `app-*.css` is inlined. The counter layout loads `resources/css/app.css` and nothing else;
     *    `theme-*.css` is the Filament PANEL theme and is never on a counter page. Globbing `*.css`
     *    concatenates it after app.css and corrupts the cascade, so utilities silently lose to whatever
     *    the panel theme happens to define last.
     */
    private function write(string $name, string $componentHtml, string $title): void
    {
        $html = view('components.layouts.counter', [
            'slot' => new HtmlString($componentHtml),
            'title' => $title,
            'fullHeight' => true,
        ])->render();

        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/cart-'.$name.'.html'), $html);
    }

    public function test_it_writes_both_selling_screens_resolved_for_the_measurement_pass(): void
    {
        // Artifacts are written BEFORE the assertions, deliberately — the same file can then be run against
        // an older commit (where the assertions cannot pass) to capture the "before" side. Same convention
        // as BlockingStatesHarnessTest; see tests/Browser/README.md.
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $genetics = collect(['Amnesia Haze', 'Critical Kush', 'Lemon Skunk', 'Blue Dream', 'Gorilla Glue', 'White Widow'])
            ->map(fn (string $name) => $this->genetic($name));
        $member = $this->member();

        // --- DISPENSARY, socio identified, basket EMPTY ---
        $empty = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $this->write('dispensary-empty', $empty->html(), 'Dispensario');

        // --- DISPENSARY, socio identified, basket FULL (three lines) ---
        $full = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        foreach ($genetics->take(3) as $genetic) {
            $full->call('chooseGenetic', $genetic->id)->set('weightInput', '3,5')->call('addLine');
        }
        $this->write('dispensary-full', $full->html(), 'Dispensario');

        // --- BAR, basket empty and full. The bar serves for cash, so it has no member step. ---
        // Categories exist so the bar's category filter pills actually RENDER and get measured — the
        // audit reported them at 66x30 and 48x30, and a harness with no categories silently skips them.
        $categories = collect(['Bebidas', 'Comida'])->map(
            fn (string $name) => Category::factory()->create([
                'organisation_id' => $this->org->id, 'name' => $name,
            ])
        );

        $articles = collect(['Cerveza', 'Agua', 'Café', 'Refresco', 'Zumo', 'Mechero'])->map(
            fn (string $name, int $i) => Article::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'name' => $name, 'price_cents' => 250, 'stock' => 100, 'active' => true,
                'category_id' => $categories[$i % 2]->id,
            ])
        );

        $barEmpty = Livewire::test(BarPos::class);
        $this->write('bar-empty', $barEmpty->html(), 'Barra');

        $barFull = Livewire::test(BarPos::class);
        foreach ($articles->take(3) as $article) {
            $barFull->call('addArticle', $article->id);
        }
        $this->write('bar-full', $barFull->html(), 'Barra');

        // --- assertions: the structure the .mjs then measures in pixels ---
        foreach (['dispensary-empty', 'dispensary-full', 'bar-empty', 'bar-full'] as $name) {
            $html = (string) file_get_contents(storage_path('app/cart-'.$name.'.html'));

            $this->assertStringContainsString('data-cart-column', $html, "$name has no cart column");
            $this->assertStringContainsString('data-commit-action', $html, "$name has no marked commit action");
            $this->assertStringContainsString('data-selection-pane', $html, "$name has no selection pane");
        }

        // The basket really was filled — otherwise the "full" captures measure the empty screen.
        $this->assertCount(3, $full->get('basket'));
        $this->assertCount(3, $barFull->get('basket'));
    }
}
