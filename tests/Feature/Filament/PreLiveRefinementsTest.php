<?php

namespace Tests\Feature\Filament;

use App\Actions\Pricing\SaveGeneticPrice;
use App\Actions\Stock\AllocateFromBatches;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DashboardAlert;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Exceptions\StockUnavailableException;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Filament\Resources\Genetics\RelationManagers\GeneticPricesRelationManager;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
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
use App\Support\Permissions;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 273 — the pre-live admin and code-style Phase 2/3 refinements that change behaviour, each pinned.
 */
class PreLiveRefinementsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->owner = $this->user(Role::OWNER);
    }

    private function user(Role $role, ?Location $at = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([($at ?? $this->location)->id]);

        return $user;
    }

    private function genetic(int $stockCg, ?int $thresholdCg = null): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'active' => true]);
        (new SaveGeneticPrice)->handle($genetic, $this->location, null, 1000, $thresholdCg);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => $stockCg, 'remaining_cg' => $stockCg, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);

        return $genetic;
    }

    // --- The dispensary grid does not grow per variety --------------------------------------------------------------

    public function test_the_dispensary_grid_runs_the_same_queries_for_one_variety_or_eleven(): void
    {
        $this->genetic(5000);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);
        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $this->owner->id]);

        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $render = function () use ($pos): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $pos->call('$refresh');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $render();
        $one = $render();

        foreach (range(1, 10) as $i) {
            $this->genetic(5000);
        }
        $render();

        $this->assertSame($one, $render(), 'the dispensary grid still runs queries per variety');
    }

    public function test_the_counter_operator_is_resolved_once_per_request(): void
    {
        CounterOperator::set($this->owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        CounterOperator::current();
        CounterOperator::current();
        CounterOperator::current();
        $userQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'from "users"'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $userQueries);

        $other = $this->user(Role::STAFF);
        CounterOperator::set($other);
        $this->assertTrue(CounterOperator::current()?->is($other), 'set() did not drop the memo');
        CounterOperator::clear();
        $this->assertNull(CounterOperator::current());
    }

    // --- Roles y permisos -------------------------------------------------------------------------------------------

    public function test_every_permission_is_on_the_roles_page_and_labelled(): void
    {
        $listed = array_merge(...array_values(Permissions::groups()));

        $this->assertEqualsCanonicalizing(Permissions::ALL, $listed, 'a permission the owner can neither grant nor revoke');
        foreach (Permissions::ALL as $permission) {
            $this->assertNotSame($permission, Permissions::label($permission), "{$permission} has no label");
        }
    }

    public function test_a_panel_only_grant_to_staff_says_it_needs_the_panel(): void
    {
        $missing = Permissions::missingDependencies(['pos.use', 'till.open', 'genetics.manage']);

        $this->assertContains(['permission' => 'genetics.manage', 'needs' => 'panel.access'], $missing);
        $this->assertSame([], Permissions::missingDependencies(['genetics.manage', 'panel.access']));
    }

    // --- Sedes a person may write to ---------------------------------------------------------------------------------

    public function test_a_managers_sede_choices_are_the_sedes_they_work_at(): void
    {
        $elsewhere = Location::factory()->create(['organisation_id' => $this->org->id]);
        $manager = $this->user(Role::MANAGER);

        $this->actingAs($manager);
        $this->assertSame([$this->location->id], array_keys(Location::assignableOptions()));

        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Livewire::test(GeneticPricesRelationManager::class, ['ownerRecord' => $genetic, 'pageClass' => EditGenetic::class])
            ->callAction('create', ['location_id' => $elsewhere->id, 'tier_id' => null, 'price_eur' => '10.00', 'active' => true])
            ->assertHasActionErrors(['location_id']);
        $this->assertFalse(GeneticPrice::query()->withoutGlobalScopes()->where('location_id', $elsewhere->id)->exists());

        $this->actingAs($this->owner);
        $this->assertEqualsCanonicalizing([$this->location->id, $elsewhere->id], array_keys(Location::assignableOptions()));
    }

    // --- The low-stock alert lands on the low varieties -------------------------------------------------------------

    public function test_the_low_stock_alert_lands_on_a_filtered_list_of_the_low_varieties(): void
    {
        $low = $this->genetic(1000, 5000);   // 10 g under a 50 g figure
        $plenty = $this->genetic(9000, 5000);
        $this->actingAs($this->owner);

        $this->assertStringContainsString('filters', DashboardAlert::GENETICS_LOW_STOCK->panelUrl());

        Livewire::test(ListGenetics::class)
            ->filterTable('low_stock')
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$plenty]);
    }

    // --- Settings and the sede form --------------------------------------------------------------------------------

    public function test_the_org_wide_low_stock_figure_is_editable_and_empty_means_automatic(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageSettings::class)->set('data.low_stock_threshold_g', '20')->call('save')->assertHasNoErrors();
        $this->assertSame(2000, (int) Settings::get('low_stock_threshold_cg'));

        Livewire::test(ManageSettings::class)->set('data.low_stock_threshold_g', null)->call('save')->assertHasNoErrors();
        $this->assertSame(0, (int) Settings::get('low_stock_threshold_cg'));
    }

    public function test_the_idle_lock_cannot_be_set_so_high_it_is_off(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(EditLocation::class, ['record' => $this->location->getRouteKey()])
            ->fillForm(['counter_idle_lock_minutes' => 100000])
            ->call('save')
            ->assertHasFormErrors(['counter_idle_lock_minutes']);
    }

    // --- Stock errors reach the operator ---------------------------------------------------------------------------

    public function test_not_enough_stock_is_a_named_error_not_a_generic_one(): void
    {
        $genetic = $this->genetic(300);

        try {
            (new AllocateFromBatches)->handle($genetic, $this->location, 500);
            $this->fail('allocating more than is there should refuse');
        } catch (StockUnavailableException $e) {
            $this->assertStringContainsString($genetic->name, $e->getMessage());
        }
    }
}
