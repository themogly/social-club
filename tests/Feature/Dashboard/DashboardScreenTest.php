<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DispensationStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Period;
use App\ViewModels\Dashboard as DashboardData;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));

        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // --- Routing / access -----------------------------------------------------------

    public function test_authenticated_staff_reaches_the_dashboard_at_root_with_no_redirect(): void
    {
        $staff = $this->user(Role::STAFF);

        $response = $this->visit($staff, $this->location->id);

        $response->assertOk(); // 200 directly — not a 3xx redirect hop
        $response->assertSee(__('Panel de control'));
    }

    public function test_guest_is_redirected_to_login_and_intended_returns_to_root(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
        // The dashboard root is preserved as the post-login destination.
        $this->assertSame(rtrim(url('/'), '/'), rtrim((string) session('url.intended'), '/'));
    }

    // --- The headline figure equals the view-model ----------------------------------

    public function test_dashboard_renders_the_seeded_contributions_figure_matching_the_viewmodel(): void
    {
        $this->dispense(3500, 2000, 1500, 350);
        $manager = $this->user(Role::MANAGER);

        $vm = $this->scopedFor($manager, Period::today());
        $this->assertSame(3500, $vm->contributionsCents());

        // The page renders in the shipped org default (es — prompt 96), so format the expected figure there.
        app()->setLocale('es');
        $formatted = Money::fromCents($vm->contributionsCents())->formatted();
        $this->visit($manager, $this->location->id)->assertOk()->assertSee($formatted);
    }

    // --- Role awareness: no rollup for a manager, no finance for staff ---------------

    public function test_manager_never_gets_the_org_rollup(): void
    {
        $manager = $this->user(Role::MANAGER);
        $this->actingAs($manager);

        app(ActiveScope::class)->setLocation($this->location->id);
        $this->assertFalse(DashboardData::for($manager, Period::today())->isRollup);

        // Even with no active location, a manager is scoped — only the OWNER rolls up.
        app(ActiveScope::class)->setLocation(null);
        $this->assertFalse(DashboardData::for($manager, Period::today())->isRollup);
    }

    public function test_owner_with_no_active_location_gets_the_rollup(): void
    {
        $owner = $this->user(Role::OWNER, assign: false);
        $this->actingAs($owner);

        app(ActiveScope::class)->setLocation(null);
        $this->assertTrue(DashboardData::for($owner, Period::today())->isRollup);

        app(ActiveScope::class)->setLocation($this->location->id);
        $this->assertFalse(DashboardData::for($owner, Period::today())->isRollup);
    }

    public function test_staff_payload_and_screen_withhold_finance_figures(): void
    {
        $this->dispense(4200, 4200, 0, 420);
        $staff = $this->user(Role::STAFF);

        $payload = $this->scopedFor($staff, Period::today())->toArray();
        $this->assertArrayNotHasKey('contributions_cents', $payload);
        $this->assertArrayNotHasKey('wallet_float_cents', $payload);

        $financeValue = Money::fromCents(4200)->formatted();
        $this->visit($staff, $this->location->id)->assertOk()->assertDontSee($financeValue);
    }

    // --- The single period control drives every figure ------------------------------

    public function test_the_period_toggle_changes_a_figure(): void
    {
        $this->dispense(3500, 3500, 0, 350);                                // today
        $this->dispense(9000, 9000, 0, 900, '2026-07-02 10:00:00');         // earlier this month

        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($this->location->id);

        $today = Money::fromCents(3500)->formatted();
        $month = Money::fromCents(12500)->formatted();

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee($today)
            ->assertDontSee($month)
            ->set('period', 'month')
            ->assertSet('period', 'month')
            ->assertSee($month);
    }

    // --- Alerts ---------------------------------------------------------------------

    public function test_an_alert_row_renders_when_its_condition_is_seeded(): void
    {
        TillSession::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'status' => TillSessionStatus::OPEN,
        ]);

        $manager = $this->user(Role::MANAGER);

        app()->setLocale('es'); // the page renders in the shipped org default (es — prompt 96)
        $message = trans_choice(':count caja abierta sin arquear|:count cajas abiertas sin arquear', 1, ['count' => 1]);
        $this->visit($manager, $this->location->id)->assertOk()->assertSee($message);
    }

    // --- Permission-filtered sidebar (denial) ---------------------------------------

    public function test_the_system_group_items_are_denied_to_staff_but_open_to_the_owner(): void
    {
        // The Sistema group (Users · Locations · Settings) is what STAFF must never see.
        // Each item's own gate is what Filament uses to filter the sidebar, so assert the
        // gate directly — Filament drops an item (and its now-empty group) when it fails.
        $staff = $this->user(Role::STAFF);
        $this->actingAs($staff);
        $this->assertFalse(UserResource::canViewAny(), 'Staff must not see Users (Sistema).');
        $this->assertFalse(LocationResource::canViewAny(), 'Staff must not see Locations (Sistema).');
        $this->assertFalse(ManageSettings::canAccess(), 'Staff must not see Settings (Sistema).');

        $owner = $this->user(Role::OWNER, assign: false);
        $this->actingAs($owner);
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(LocationResource::canViewAny());
        $this->assertTrue(ManageSettings::canAccess());
    }

    public function test_staff_do_not_see_a_system_item_in_the_rendered_sidebar(): void
    {
        // Belt-and-braces at the HTML level (single request — the built nav is cached
        // per process, so a second differently-scoped visit here would be unreliable):
        // "Ajustes" is the Settings label and appears nowhere in the dashboard body.
        $staff = $this->user(Role::STAFF);
        $this->visit($staff, $this->location->id)->assertOk()->assertDontSee(__('Ajustes'));
    }

    // --- Empty state ----------------------------------------------------------------

    public function test_a_brand_new_location_shows_a_designed_empty_state(): void
    {
        $fresh = Location::factory()->create(['organisation_id' => $this->org->id]);
        $manager = $this->user(Role::MANAGER);
        $manager->locations()->attach($fresh->id);

        $this->visit($manager, $fresh->id)
            ->assertOk() // not a blank/error page
            ->assertSee(__('Nada dispensado en este período'));
    }

    // --- Stat-card labels survive over the delta chip (prompt 129) -------------------

    public function test_delta_bearing_stat_cards_keep_a_readable_label(): void
    {
        // The three cards that carry a delta chip — Aportaciones, Dispensado, Transacciones — are the ones
        // prompt 101's single-line ellipsis erased on a narrow card. Their labels must render in full...
        $this->dispense(3500, 2000, 1500, 350);
        $owner = $this->user(Role::OWNER);

        app()->setLocale('es'); // the page renders in the shipped org default (es — prompt 96)
        $response = $this->visit($owner, $this->location->id)->assertOk();
        foreach (['Aportaciones', 'Dispensado', 'Transacciones'] as $label) {
            $response->assertSee($label);
        }

        // ...and the stylesheet must WRAP the label to two lines rather than truncate it to one ellipsised
        // line (which is what starved these labels beside the chip). This is the structural guard; the full
        // proof is a screenshot at 768 / 800 / 1024 / 1280 — owed, no browser here.
        $styles = file_get_contents(resource_path('views/filament/pages/partials/dashboard-styles.blade.php'));
        $this->assertMatchesRegularExpression('/\.csc-card-label\s*\{[^}]*-webkit-line-clamp:\s*2/', $styles);
        $this->assertDoesNotMatchRegularExpression('/\.csc-card-label\s*\{[^}]*white-space:\s*nowrap/', $styles);
    }

    // --- Stat-card labels never break mid-word (prompt 144) --------------------------

    public function test_stat_card_labels_never_break_mid_word(): void
    {
        // Prompt 129's two-line wrap left a residue: at four-up, the card was so narrow that
        // "Contributions"/"Transacciones" broke MID-WORD ("Contri‑butions"). The fix has three
        // load-bearing parts, each proven necessary by a Playwright range-rects harness (owed in
        // CI, no browser here) — this is the structural guard that pins all three so a later edit
        // can't silently revert one:
        $styles = file_get_contents(resource_path('views/filament/pages/partials/dashboard-styles.blade.php'));
        $markup = file_get_contents(resource_path('views/components/dashboard/stat-card.blade.php'));

        // (1) break-word, NOT anywhere — `anywhere` collapses the label's min-content width to one
        //     character, which is what let the flex algorithm shatter the word in the first place.
        $this->assertMatchesRegularExpression('/\.csc-card-label\s*\{[^}]*overflow-wrap:\s*break-word/', $styles);
        $this->assertDoesNotMatchRegularExpression('/\.csc-card-label\s*\{[^}]*overflow-wrap:\s*anywhere/', $styles);

        // (2) The delta chip sits BELOW the label in a column wrapper, so the label owns the full
        //     header width. With the chip beside it, even a 13rem card breaks these two labels.
        $this->assertMatchesRegularExpression('/\.csc-card-headmain\s*\{[^}]*flex-direction:\s*column/', $styles);
        $this->assertMatchesRegularExpression('/csc-card-headmain[\s\S]{0,400}?csc-card-label[\s\S]{0,400}?csc-delta/', $markup);

        // (3) auto-fit with a readable min-width instead of a fixed four-up — a four-up row in the
        //     content column made each card too narrow even with the chip below.
        $this->assertMatchesRegularExpression('/grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(13rem/', $styles);
        $this->assertDoesNotMatchRegularExpression('/\.csc-cards\s*\{[^}]*repeat\(4,/', $styles);
    }

    // --- No N+1: the query count is bounded and stable across data volume ------------

    public function test_dashboard_query_count_is_bounded_and_does_not_grow_with_volume(): void
    {
        $manager = $this->user(Role::MANAGER);
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->seedDispensations(8);
        $first = $this->countQueries(fn () => Livewire::test(Dashboard::class)->assertOk());

        $this->seedDispensations(24); // triple the transactional volume
        $second = $this->countQueries(fn () => Livewire::test(Dashboard::class)->assertOk());

        $this->assertLessThan(120, $first, "Dashboard issued {$first} queries — suspECT N+1.");
        // The aggregates are SQL — the count must not scale with rows.
        $this->assertLessThanOrEqual($first + 2, $second, "Query count grew from {$first} to {$second} with more rows — N+1.");
    }

    // --- Helpers --------------------------------------------------------------------

    private function user(Role $role, bool $assign = true): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        if ($assign) {
            $user->locations()->attach($this->location->id);
        }

        return $user;
    }

    private function scopedFor(User $user, Period $period): DashboardData
    {
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return DashboardData::for($user, $period);
    }

    private function visit(User $user, ?string $locationId): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['scope.organisation_id' => $this->org->id, 'scope.location_id' => $locationId])
            ->get('/');
    }

    private function dispense(int $total, int $cash, int $wallet, int $gramsCg, ?string $at = null): Dispensation
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => $total, 'cash_cents' => $cash,
            'wallet_cents' => $wallet, 'dispensed_at' => $at ?? now(),
        ]);
        DispensationLine::factory()->create([
            'dispensation_id' => $dispensation->id, 'genetic_id' => $this->genetic->id, 'grams_cg' => $gramsCg,
        ]);

        return $dispensation;
    }

    private function seedDispensations(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->dispense(1000, 1000, 0, 100);
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
