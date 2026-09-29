<?php

namespace Tests\Feature\Locations;

use App\Enums\Role;
use App\Filament\Pages\Rat;
use App\Filament\Pages\RegistroDispensacion;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Locations\LocationResource;
use App\Livewire\LocationSwitcher as LocationSwitcherComponent;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;
use App\Support\PanelReturnUrl;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 148 — the active sede is never ambiguous. A single-sede org names its one sede instead of an "All
 * locations" rollup of one; the rollup is offered only to an owner who can reach more than one; and a batch is
 * REFUSED rather than attributed to an arbitrary sede when none is active (a per-premises stock-ceiling and
 * registro-de-dispensación matter, not cosmetic). Run on MySQL.
 */
class ActiveSedeTest extends TestCase
{
    use PostsLivewireOverHttp;
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::OWNER->value);

        return $u;
    }

    private function location(bool $active = true): Location
    {
        return Location::factory()->create(['organisation_id' => $this->org->id, 'active' => $active]);
    }

    public function test_an_owner_of_one_sede_has_it_active_by_default_and_no_rollup(): void
    {
        $owner = $this->owner();
        $location = $this->location();
        $this->actingAs($owner);

        $switcher = app(LocationSwitcher::class);
        $this->assertFalse($switcher->canSwitchToAll($owner));                 // no "All locations"
        $this->assertSame($location->id, $switcher->defaultLocationId($owner));

        Livewire::test(LocationSwitcherComponent::class)->assertSet('active', $location->id); // the topbar names it
    }

    public function test_an_owner_of_two_sedes_still_gets_the_rollup_and_defaults_to_it(): void
    {
        $owner = $this->owner();
        $this->location();
        $this->location();
        $this->actingAs($owner);

        $switcher = app(LocationSwitcher::class);
        $this->assertTrue($switcher->canSwitchToAll($owner));
        $this->assertNull($switcher->defaultLocationId($owner));

        Livewire::test(LocationSwitcherComponent::class)->assertSet('active', null); // stays in the rollup
    }

    public function test_deactivating_a_location_collapses_the_switcher_to_the_one_remaining(): void
    {
        $owner = $this->owner();
        $a = $this->location();
        $b = $this->location();
        $this->actingAs($owner);
        $this->assertTrue(app(LocationSwitcher::class)->canSwitchToAll($owner));

        $b->update(['active' => false]);

        $switcher = app(LocationSwitcher::class);
        $this->assertFalse($switcher->canSwitchToAll($owner));
        $this->assertSame($a->id, $switcher->defaultLocationId($owner));
    }

    public function test_a_manager_with_one_assigned_sede_sees_it_named_and_cannot_rollup(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $location = $this->location();
        $manager->locations()->sync([$location->id]);
        $this->actingAs($manager);

        $switcher = app(LocationSwitcher::class);
        $this->assertFalse($switcher->canSwitchToAll($manager));
        $this->assertSame($location->id, $switcher->defaultLocationId($manager));
    }

    public function test_creating_a_batch_with_no_active_sede_is_refused_not_guessed(): void
    {
        $owner = $this->owner();
        $this->location();
        $this->location(); // two sedes → owner defaults to the rollup (no active sede)
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation(null); // the rollup
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        // Prompt 238 kept this compliance invariant (stock is never guessed into a sede) but moved the
        // MECHANISM: the rollup no longer Halts with a notification — the sede is a REQUIRED form field, so a
        // submit that names no sede is refused at validation, before any write. Still refused, still nothing
        // created; the refusal is now the field the owner must fill rather than a runtime error after the fact.
        Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $genetic->product_type->value, 'genetic_id' => $genetic->id, 'grams' => 100, 'cost_per_gram_eur' => 3])
            ->call('create')
            ->assertHasFormErrors(['location_id' => 'required']);

        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count()); // nothing guessed into existence
    }

    public function test_a_batch_is_created_at_the_active_sede_never_an_arbitrary_one(): void
    {
        $owner = $this->owner();
        $a = $this->location(); // created first — a "pick the first row" bug would land here
        $b = $this->location();
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($b->id); // working IN sede B
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(CreateBatch::class)
            ->fillForm(['product_type' => $genetic->product_type->value, 'genetic_id' => $genetic->id, 'grams' => 100, 'cost_per_gram_eur' => 3, 'sale_price_eur' => 8])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = Batch::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($b->id, $batch->location_id);   // the ACTIVE sede
        $this->assertNotSame($a->id, $batch->location_id); // not the first row
    }

    public function test_the_batches_list_shows_the_location_only_when_more_than_one_exists(): void
    {
        $owner = $this->owner();
        $a = $this->location();
        $b = $this->location();
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $b->id, 'genetic_id' => $genetic->id]);
        $this->actingAs($owner);
        app(ActiveScope::class)->setLocation($b->id);

        Livewire::test(ListBatches::class)->assertCanRenderTableColumn('location.name');
    }

    public function test_zero_locations_the_switcher_does_not_error_and_the_owner_can_reach_locations_create(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(LocationSwitcherComponent::class)->assertSet('active', null); // no error, no default
        $this->get(LocationResource::getUrl('create'))->assertOk();
    }

    // --- Prompt 284 — switching sede keeps you on the page you were on ----------------------------------------
    //
    // Driven through the REAL page: the switcher's snapshot comes from a GET of the page (its mount() runs on that
    // request and records the URL), and switchTo() is POSTed to Livewire's real update endpoint — where
    // `request()->url()` is the update URI, which is exactly the trap. `Livewire::test()` has no page URL at all.

    /** @return array{0: Location, 1: Location, 2: Batch} */
    private function twoSedesWithABatchEach(): array
    {
        $owner = $this->owner();
        $centro = $this->location();
        $norte = $this->location();
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $inCentro = Batch::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $centro->id, 'genetic_id' => $genetic->id, 'batch_no' => 'B-CENTRO1', 'label' => 'B-CENTRO1']); // the list shows names, not lote numbers (298)
        Batch::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $norte->id, 'genetic_id' => $genetic->id, 'batch_no' => 'B-NORTE22', 'label' => 'B-NORTE22']);
        $this->actingAs($owner);

        return [$centro, $norte, $inCentro];
    }

    /** Switch from the page at `$uri` and return where the switcher sent us. */
    private function switchFrom(string $uri, ?string $to): ?string
    {
        $response = $this->livewirePost($this->snapshotFrom($uri, 'location-switcher'), [], [['switchTo', [$to ?? '']]])->assertOk();

        return $response->json('components.0.effects.redirect');
    }

    public function test_switching_to_the_rollup_on_the_lotes_list_stays_on_the_lotes_list(): void
    {
        [$centro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $list = BatchResource::getUrl('index');

        $this->assertSame($list, $this->switchFrom($list, null));
        $this->assertNull(session('scope.location_id'));
    }

    public function test_switching_from_the_rollup_to_one_sede_stays_on_the_lotes_list(): void
    {
        [, $norte] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation(null);
        $list = BatchResource::getUrl('index');

        $this->assertSame($list, $this->switchFrom($list, $norte->id));
        $this->assertSame($norte->id, session('scope.location_id'));
    }

    public function test_the_query_string_with_search_and_filters_survives_the_switch(): void
    {
        [$centro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $filtered = BatchResource::getUrl('index').'?search=amnesia&tableFilters%5Bstatus%5D%5Bvalue%5D=open';

        $this->assertSame($filtered, $this->switchFrom($filtered, null));
    }

    public function test_a_search_typed_after_the_page_loaded_survives_but_only_for_the_same_page(): void
    {
        // Filament writes the search into the address bar AFTER load (replaceState), so the browser sends its current
        // address with the switch. It is honoured only as the query string of the page the switcher mounted on.
        [$centro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $list = BatchResource::getUrl('index');
        $snapshot = fn (): string => $this->snapshotFrom($list, 'location-switcher');

        $typed = $list.'?search=verano';
        $this->assertSame($typed, $this->livewirePost($snapshot(), [], [['switchTo', ['', $typed]]])->json('components.0.effects.redirect'));

        // Another page, or another host, is ignored: back to the page it mounted on, never where the client pointed.
        foreach ([url('/rat'), 'https://evil.example/batches?search=x', url('/counter/till')] as $elsewhere) {
            $this->assertSame($list, $this->livewirePost($snapshot(), [], [['switchTo', ['', $elsewhere]]])->json('components.0.effects.redirect'));
        }
    }

    public function test_on_a_batch_edit_page_switching_to_its_sede_or_the_rollup_stays_on_it(): void
    {
        [$centro, , $inCentro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation(null);
        $edit = BatchResource::getUrl('edit', ['record' => $inCentro]);

        $this->assertSame($edit, $this->switchFrom($edit, $centro->id)); // the batch's own sede
        $this->assertSame($edit, $this->switchFrom($edit, null));        // the rollup sees every sede
    }

    public function test_on_a_batch_edit_page_switching_to_a_sede_it_is_not_at_lands_on_the_list_not_a_404(): void
    {
        [$centro, $norte, $inCentro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $edit = BatchResource::getUrl('edit', ['record' => $inCentro]);

        $target = $this->switchFrom($edit, $norte->id);

        $this->assertSame(BatchResource::getUrl('index'), $target);
        $this->get($edit)->assertNotFound();   // what staying on the same URL would have shown
        $this->get((string) $target)->assertOk();
    }

    public function test_a_tampered_return_url_falls_back_to_the_dashboard(): void
    {
        [$centro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $snapshot = $this->snapshotFrom(BatchResource::getUrl('index'), 'location-switcher');

        // The client cannot change it: the property is #[Locked], so an update to it is refused outright.
        $this->livewirePost($snapshot, ['returnUrl' => 'https://evil.example/'], [['switchTo', ['']]])->assertStatus(500);

        // And the resolver never follows anything off-host or outside the panel, whatever got in.
        foreach (['https://evil.example/batches', '//evil.example/batches', '/\\evil.example', 'javascript:alert(1)',
            url('/counter/till'), url('/socio'), 'http://other-host.test'.parse_url(BatchResource::getUrl('index'), PHP_URL_PATH), '', null] as $tampered) {
            $this->assertSame(url('/'), PanelReturnUrl::after($tampered), "Followed: {$tampered}");
        }
    }

    public function test_a_custom_panel_page_is_kept_too(): void
    {
        [$centro] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $registro = RegistroDispensacion::getUrl().'?month=2026-09';

        $this->assertSame($registro, $this->switchFrom($registro, null));
        $this->assertSame(Rat::getUrl(), PanelReturnUrl::after(Rat::getUrl()));
    }

    public function test_the_scope_really_changes_after_the_redirect(): void
    {
        [$centro, $norte] = $this->twoSedesWithABatchEach();
        app(ActiveScope::class)->setLocation($centro->id);
        $list = BatchResource::getUrl('index');
        $this->get($list)->assertSee('B-CENTRO1')->assertDontSee('B-NORTE22');

        $target = $this->switchFrom($list, $norte->id);

        // The full reload re-resolves every scoped query under the new sede — the reason it stays a reload.
        $this->get((string) $target)->assertSee('B-NORTE22')->assertDontSee('B-CENTRO1');
    }
}
