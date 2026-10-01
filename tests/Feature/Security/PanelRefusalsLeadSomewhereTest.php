<?php

namespace Tests\Feature\Security;

use App\Actions\Counter\RegisterCounterTerminal;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Filament\Pages\Auth\ConfirmIdentity;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use App\Support\PanelIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\Support\ExplodingStore;
use Tests\TestCase;

/**
 * Prompt 310 — "I got a 403 error when you go to Bar and restock. It only happened once." The owner's *Reponer* is a
 * Livewire request, and `ConfirmIdentityForPinSessions` answered a PIN session without a current confirmation (never
 * given, expired, someone else's PIN in another tab, a cache that threw) with a BARE 403 — Livewire's raw error box. A
 * reload redirected properly, hence "only once". Every panel refusal of a Livewire request now carries its reason and
 * where to go (`X-Csc-Reason` / `X-Csc-Location`, read by one panel hook), remembers the page, and does nothing else.
 */
class PanelRefusalsLeadSomewhereTest extends TestCase
{
    use ChangesRolePermissions, PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private User $owner;

    private User $manager;

    private Article $papel;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = $this->person(Role::OWNER, '11119999');
        $this->manager = $this->person(Role::MANAGER, '22228888');
        $this->papel = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Papel', 'stock' => 3]);
        $this->withCredentials();
    }

    private function person(Role $role, string $pin): User
    {
        $user = User::factory()->create(['pin' => $pin, 'password' => 'secreto-'.$pin]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->centro->id]);

        return $user;
    }

    private function tablet(): string
    {
        ['terminal' => $terminal, 'token' => $token] = (new RegisterCounterTerminal)->handle($this->manager, $this->centro, 'Tablet barra');
        Auth::login($this->manager);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        Auth::logout();

        return CounterTerminals::cookieValue($terminal, $token);
    }

    private function pinSignIn(string $cookie, string $pin): void
    {
        $this->withCookie(CounterTerminals::COOKIE, $cookie);
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), ['operatorPin' => $pin], [['unlockOperator']])->assertOk();
    }

    private function confirm(string $password): void
    {
        $snapshot = $this->snapshotFrom(ConfirmIdentity::getUrl(), ConfirmIdentity::class);
        $this->livewirePost($snapshot, ['data.password' => $password], [['confirm']])->assertOk();
    }

    private function products(): string
    {
        return ArticleResource::getUrl('index');
    }

    /** *Reponer* opened on Papel, as the browser has it after the modal opened: the snapshot to press "Reponer" with. */
    private function restockOpen(): string
    {
        $list = $this->snapshotFrom($this->products(), ListArticles::class);
        $mounted = $this->livewirePost($list, [], [['mountTableAction', ['restock', $this->papel->getKey()]]], ['Referer' => $this->products()])->assertOk();

        return (string) $mounted->json('components.0.snapshot');
    }

    private function pressRestock(string $snapshot, int $units): TestResponse
    {
        return $this->livewirePost($snapshot, ['mountedActions.0.data.units' => (string) $units], [['callMountedAction']], ['Referer' => $this->products()]);
    }

    private function intakes(): int
    {
        return StockMovement::query()->withoutGlobalScopes()->where('type', StockMovementType::INTAKE->value)->where('stockable_id', $this->papel->id)->count();
    }

    private function assertLeadsTo(TestResponse $response, string $reason, string $to): void
    {
        $response->assertForbidden();
        $this->assertSame($reason, $response->headers->get('X-Csc-Reason'));
        $this->assertSame($to, $response->headers->get('X-Csc-Location'));
    }

    // --- 1. Someone else's PIN in another tab: no confirmation for them ---------------------------------------------

    public function test_a_pin_session_without_a_confirmation_is_sent_to_confirm_and_nothing_is_restocked(): void
    {
        $cookie = $this->tablet();
        $this->pinSignIn($cookie, '22228888');
        $this->confirm('secreto-22228888');
        $open = $this->restockOpen();

        // The owner's PIN on the counter in the other tab: the session is now theirs, unconfirmed.
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), [], [['lockCounter']])->assertOk();
        $this->pinSignIn($cookie, '11119999');

        $this->assertLeadsTo($this->pressRestock($open, 5), 'confirm-identity', ConfirmIdentity::getUrl());
        $this->assertSame(0, $this->intakes(), 'the refused restock was recorded');
        $this->assertSame(3, $this->papel->fresh()->stock);
        $this->assertSame($this->products(), session('url.intended'));
    }

    // --- 3. The confirmation expires while the page is open --------------------------------------------------------------

    public function test_an_expired_confirmation_is_sent_to_confirm_and_pressing_again_after_confirming_works(): void
    {
        $cookie = $this->tablet();
        $this->pinSignIn($cookie, '22228888');
        $this->confirm('secreto-22228888');
        $open = $this->restockOpen();

        $this->travel(PanelIdentity::SHIFT_HOURS + 1)->hours();
        $this->assertLeadsTo($this->pressRestock($open, 5), 'confirm-identity', ConfirmIdentity::getUrl());
        $this->assertSame(0, $this->intakes());

        // Confirming lands back on Productos; pressing Reponer again records it, once.
        $snapshot = $this->snapshotFrom(ConfirmIdentity::getUrl(), ConfirmIdentity::class);
        $this->livewirePost($snapshot, ['data.password' => 'secreto-22228888'], [['confirm']])
            ->assertOk()->assertJsonPath('components.0.effects.redirect', $this->products());
        $this->pressRestock($open, 5)->assertOk();
        $this->assertSame(1, $this->intakes());
        $this->assertSame(8, $this->papel->fresh()->stock);
    }

    // --- 4. A cache that throws: still asked, and told why ----------------------------------------------------------------

    public function test_a_cache_that_throws_still_asks_logs_a_warning_and_says_why(): void
    {
        Log::spy();
        Cache::extend('exploding', fn (): Repository => new Repository(new ExplodingStore));
        // The store PanelIdentity keeps the confirmation on — the limiter store since 344 (the default store with it).
        config(['cache.stores.exploding' => ['driver' => 'exploding'], 'cache.default' => 'exploding', 'cache.limiter' => 'exploding']);
        $this->actingAs($this->manager);
        session(['auth.via_pin' => true]);

        $this->assertTrue(PanelIdentity::mustConfirm($this->manager));
        $this->assertTrue(PanelIdentity::mustConfirm($this->manager));
        Log::shouldHaveReceived('warning')->once();

        $this->get(ConfirmIdentity::getUrl())->assertOk()
            ->assertSee(__('No se ha podido comprobar la confirmación (caché no disponible). Vuelve a introducir tu contraseña.'));
    }

    // --- 5. The other panel refusals -------------------------------------------------------------------------------------

    public function test_a_locked_counter_or_a_counter_only_account_on_a_livewire_request_goes_to_the_counter(): void
    {
        $this->actingAs($this->owner);
        $list = $this->snapshotFrom($this->products(), ListArticles::class);

        // The owner's panel tab behind a counter that locked (267: locked means locked — for button presses too).
        session(['counter.location_id' => $this->centro->id]);
        CounterOperator::clear();
        $this->assertLeadsTo($this->livewirePost($list, [], [['mountTableAction', ['restock', $this->papel->getKey()]]]), 'counter', route('counter.home'));

        // A counter-only account (no panel.access).
        session()->forget('counter.location_id');
        $staff = $this->person(Role::STAFF, '33337777');
        $this->actingAs($staff);
        $this->assertLeadsTo($this->livewirePost($list, [], [['mountTableAction', ['restock', $this->papel->getKey()]]]), 'counter', route('counter.home'));
    }

    public function test_someone_with_no_sede_goes_to_a_page_that_says_so(): void
    {
        $this->actingAs($this->owner);
        $list = $this->snapshotFrom($this->products(), ListArticles::class);

        $this->manager->locations()->sync([]);
        $this->flushSession(); // a new person, a new session (AuthenticateSession signs out a mid-session user swap)
        $this->actingAs($this->manager->fresh());
        $this->assertLeadsTo($this->livewirePost($list), 'no-location', route('panel.no-location'));
        $this->get('/')->assertRedirect(route('panel.no-location'));
        $this->get(route('panel.no-location'))->assertOk()
            ->assertSee(__('No tienes ninguna sede asignada. Pide a un responsable que te asigne una.'))
            ->assertSee(route('counter.home'), false);
    }

    // --- 6. A password session is never asked -----------------------------------------------------------------------------

    public function test_a_password_session_is_never_asked_and_its_restock_just_works(): void
    {
        $this->actingAs($this->manager);
        $open = $this->restockOpen();

        $this->pressRestock($open, 2)->assertOk();
        $this->assertSame(1, $this->intakes());
    }

    // --- The page: the hook, and a confirmation about to expire -------------------------------------------------------------

    public function test_every_panel_page_carries_the_refusal_hook(): void
    {
        $this->actingAs($this->manager)->get($this->products())->assertOk()->assertSee('data-panel-refusal-hook', false);
    }

    public function test_a_confirmation_about_to_expire_offers_to_renew_and_returns_to_the_page(): void
    {
        $cookie = $this->tablet();
        $this->pinSignIn($cookie, '22228888');
        $this->confirm('secreto-22228888');

        $this->get($this->products())->assertOk()->assertDontSee(__('Tu confirmación caduca pronto.'));

        $this->travel(PanelIdentity::SHIFT_HOURS * 60 - 10)->minutes();
        $html = (string) $this->get($this->products())->assertOk()->assertSee(__('Tu confirmación caduca pronto.'))->getContent();
        preg_match('/href="([^"]+)"[^>]*data-renew-confirmation/', $html, $link);
        $this->assertNotEmpty($link, 'no Renovar link');

        $this->get(html_entity_decode($link[1]))->assertOk();
        $snapshot = $this->snapshotFrom(ConfirmIdentity::getUrl(), ConfirmIdentity::class);
        $this->livewirePost($snapshot, ['data.password' => 'secreto-22228888'], [['confirm']])
            ->assertJsonPath('components.0.effects.redirect', $this->products());
    }
}
