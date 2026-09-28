<?php

namespace Tests\Feature\Counter;

use App\Actions\Counter\RegisterCounterTerminal;
use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Pages\CounterTerminals as CounterTerminalsPage;
use App\Http\Middleware\RecogniseCounterTerminal;
use App\Livewire\Counter\TillSession;
use App\Models\AuditLog;
use App\Models\CounterTerminal;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 289 — a registered counter: the tablet opens on the PIN pad, never on a password.
 *
 * Not a PIN on the public /login (a 4-digit PIN tested against everyone's at once, on the open internet): a manager
 * registers the TABLET once, and a registered tablet always opens on the counter's lock surface. The tablet is what
 * you have; the PIN is what you know. The terminal authorises nothing by itself — every action is still the PIN
 * operator's (255).
 */
class RegisteredCounterTerminalTest extends TestCase
{
    use PostsLivewireOverHttp;
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->manager = $this->person(Role::MANAGER, '2222', [$this->centro]);
        $this->staff = $this->person(Role::STAFF, '3333', [$this->centro]);
        // A browser sends its cookies with Livewire's JSON updates; Laravel's test client only does when asked.
        $this->withCredentials();
    }

    /** @param list<Location> $sedes */
    private function person(Role $role, string $pin, array $sedes): User
    {
        $user = User::factory()->create(['pin' => $pin]);
        $user->assignRole($role->value);
        $user->locations()->sync(array_map(fn (Location $l): string => $l->id, $sedes));

        return $user;
    }

    /** @return array{0: CounterTerminal, 1: string} a registered terminal and its cookie value */
    private function terminal(?Location $at = null): array
    {
        ['terminal' => $terminal, 'token' => $token] = (new RegisterCounterTerminal)->handle($this->manager, $at ?? $this->centro, 'Tablet barra');

        return [$terminal, CounterTerminals::cookieValue($terminal, $token)];
    }

    private function openTill(): void
    {
        Auth::login($this->manager);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        Auth::logout();
    }

    // --- Registering ------------------------------------------------------------------------------------------------

    public function test_registering_needs_the_permission_and_the_pin_and_stores_only_the_hash(): void
    {
        $this->actingAs($this->manager);
        app(ActiveScope::class)->setLocation($this->centro->id);
        CounterOperator::set($this->manager);
        session(['counter.location_id' => $this->centro->id]);
        $this->openTill();
        $this->actingAs($this->manager);

        // Wrong PIN → nothing.
        Livewire::test(TillSession::class)
            ->call('beginRegisterTerminal')
            ->set('terminalName', 'Tablet barra')->set('terminalLocationId', $this->centro->id)->set('terminalPin', '9999')
            ->call('confirmRegisterTerminal');
        $this->assertSame(0, CounterTerminal::query()->withoutGlobalScopes()->count());

        Livewire::test(TillSession::class)
            ->call('beginRegisterTerminal')
            ->set('terminalName', '  Tablet barra  ')->set('terminalLocationId', $this->centro->id)->set('terminalPin', '2222')
            ->call('confirmRegisterTerminal');

        $terminal = CounterTerminal::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Tablet barra', $terminal->name);
        $this->assertSame($this->centro->id, $terminal->location_id);

        $cookie = Cookie::queued(CounterTerminals::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertGreaterThan(now()->addDays(399)->getTimestamp(), $cookie->getExpiresTime());

        [$id, $token] = explode('|', (string) $cookie->getValue());
        $this->assertSame($terminal->id, $id);
        $this->assertSame(hash('sha256', $token), $terminal->token_hash);
        $this->assertStringNotContainsString($token, json_encode($terminal->getAttributes()) ?: '');
        $this->assertTrue(AuditLog::query()->where('action', 'counter.terminal.registered')->exists());

        // Staff (no terminals.manage) cannot.
        CounterOperator::set($this->staff);
        $this->actingAs($this->staff);
        Livewire::test(TillSession::class)
            ->call('beginRegisterTerminal')
            ->set('terminalName', 'Otra')->set('terminalLocationId', $this->centro->id)->set('terminalPin', '3333')
            ->call('confirmRegisterTerminal');
        $this->assertSame(1, CounterTerminal::query()->withoutGlobalScopes()->count());
    }

    public function test_a_manager_cannot_register_a_tablet_to_a_sede_that_is_not_theirs(): void
    {
        $this->expectException(AuthorizationException::class);
        (new RegisterCounterTerminal)->handle($this->manager, $this->norte, 'Tablet norte');
    }

    // --- A registered tablet with no session -------------------------------------------------------------------------

    public function test_the_front_door_and_login_open_on_the_counter_pin_surface(): void
    {
        [, $cookie] = $this->terminal();
        $this->openTill();
        $this->withCookie(CounterTerminals::COOKIE, $cookie);

        $this->get('/')->assertRedirect(route('counter.home'));
        $this->get('/login')->assertRedirect(route('counter.home'));
        $this->get('/counter')->assertOk()->assertSee('data-surface-mode="unidentified"', false);
        $this->assertFalse(Auth::check());

        // The way out to a password is still there.
        $this->get('/login?password=1')->assertOk();
    }

    public function test_with_no_operator_no_screen_sends_member_data(): void
    {
        [, $cookie] = $this->terminal();
        $this->openTill();
        Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Zacarías', 'last_name' => 'Distintivo', 'member_no' => 'Z-99999']);
        $this->withCookie(CounterTerminals::COOKIE, $cookie);

        foreach (['/counter', '/counter/checkin', '/counter/members', '/counter/till', '/counter/pos', '/counter/bar'] as $uri) {
            $html = (string) $this->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('Zacarías', $html, "{$uri} sent a member's name before a PIN");
            $this->assertStringNotContainsString('Distintivo', $html, "{$uri} sent a member's name before a PIN");
            $this->assertStringNotContainsString('Z-99999', $html, "{$uri} sent a member number before a PIN");
        }
    }

    public function test_a_correct_pin_signs_the_person_in_and_wrong_pins_count_sede_wide(): void
    {
        [, $cookie] = $this->terminal();
        $this->openTill();
        $this->withCookie(CounterTerminals::COOKIE, $cookie);

        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, ['operatorPin' => '0000'], [['unlockOperator']])->assertOk();
        $this->assertSame(
            (new UnlockOperator)->maxAttemptsAt($this->centro) - 1,
            (new UnlockOperator)->attemptsRemaining($this->centro, 'counter-pin:'.$this->centro->id),
        );

        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, ['operatorPin' => '3333'], [['unlockOperator']])->assertOk();
        $this->assertSame($this->staff->id, Auth::id());
        $this->assertSame($this->staff->id, CounterOperator::id());
        $this->assertTrue(AuditLog::query()->where('action', 'counter.operator.signed_in')->exists());
    }

    // --- Revoked or tampered cookies ----------------------------------------------------------------------------------

    public function test_a_revoked_terminal_is_cleared_and_goes_to_the_normal_login(): void
    {
        [$terminal, $cookie] = $this->terminal();
        $terminal->forceFill(['revoked_at' => now(), 'revoked_by' => $this->manager->id])->save();

        $response = $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/counter');

        $response->assertRedirect('/');
        $response->assertCookieExpired(CounterTerminals::COOKIE);
    }

    public function test_a_tampered_token_or_an_unknown_terminal_is_treated_as_absent(): void
    {
        [$terminal] = $this->terminal();

        $this->withCookie(CounterTerminals::COOKIE, $terminal->id.'|'.str_repeat('x', 64))->get('/')->assertRedirect('/login');
        $this->withCookie(CounterTerminals::COOKIE, '01XXXXXXXXXXXXXXXXXXXXXXXX|'.str_repeat('y', 64))->get('/counter')->assertRedirect('/');
    }

    // --- Locking ------------------------------------------------------------------------------------------------------

    public function test_locking_on_a_terminal_logs_the_person_out_and_keeps_the_session(): void
    {
        [, $cookie] = $this->terminal();
        $this->openTill();
        $this->withCookie(CounterTerminals::COOKIE, $cookie);

        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, ['operatorPin' => '3333'], [['unlockOperator']]);
        $this->assertSame($this->staff->id, Auth::id());
        session(['counter.pos.basket' => [['genetic_id' => 'g', 'grams_cg' => 100]]]);

        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, [], [['lockCounter']])->assertOk();

        $this->assertFalse(Auth::guard('web')->check(), 'locked means NOBODY is signed in on a registered tablet');
        $this->assertNull(CounterOperator::id());
        $this->assertSame($this->centro->id, session('counter.location_id'));
        $this->assertNotNull(session('counter.pos.basket'), 'the basket survives the lock');
    }

    public function test_locking_on_an_unregistered_device_is_unchanged(): void
    {
        $this->openTill();
        $this->actingAs($this->manager);
        session(['counter.location_id' => $this->centro->id]);
        CounterOperator::set($this->staff);

        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, [], [['lockCounter']])->assertOk();

        $this->assertTrue(Auth::guard('web')->check(), 'an ordinary browser keeps its login when locked');
        $this->assertNull(CounterOperator::id());
    }

    // --- The cookie over time -----------------------------------------------------------------------------------------

    public function test_last_seen_is_written_at_most_hourly_and_renews_the_cookie(): void
    {
        [$terminal, $cookie] = $this->terminal();
        $this->openTill();
        $this->withCookie(CounterTerminals::COOKIE, $cookie);

        $this->get('/counter')->assertCookie(CounterTerminals::COOKIE);
        $seen = $terminal->fresh()->last_seen_at;
        $this->assertNotNull($seen);
        Cookie::flushQueuedCookies(); // the test container keeps the jar between requests; a real request starts empty

        $this->travel(20)->minutes();
        $this->get('/counter')->assertCookieMissing(CounterTerminals::COOKIE);
        $this->assertTrue($terminal->fresh()->last_seen_at->equalTo($seen));

        $this->travel(45)->minutes();
        $this->get('/counter')->assertCookie(CounterTerminals::COOKIE);
        $this->assertTrue($terminal->fresh()->last_seen_at->greaterThan($seen));
    }

    // --- Lockdown, the panel and permissions -------------------------------------------------------------------------

    public function test_a_terminal_gets_the_lockdown_page_even_in_a_drill(): void
    {
        [, $cookie] = $this->terminal();
        OrganisationLockdown::create(['organisation_id' => $this->org->id, 'locked_at' => now(), 'is_drill' => true]);

        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/counter')->assertStatus(503);
    }

    public function test_the_panel_lists_and_revokes_terminals_a_manager_seeing_only_their_sedes(): void
    {
        [$centro] = $this->terminal($this->centro);
        $owner = $this->person(Role::OWNER, '1111', [$this->centro, $this->norte]);
        ['terminal' => $norte] = (new RegisterCounterTerminal)->handle($owner, $this->norte, 'Tablet norte');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->manager)->test(CounterTerminalsPage::class)
            ->assertSee('Tablet barra')->assertDontSee('Tablet norte')
            ->callAction('revoke', arguments: ['terminal' => $centro->id]);

        $this->assertNotNull($centro->fresh()->revoked_at);
        $this->assertTrue(AuditLog::query()->where('action', 'counter.terminal.revoked')->exists());

        Livewire::actingAs($this->manager)->test(CounterTerminalsPage::class)
            ->callAction('revoke', arguments: ['terminal' => $norte->id]);
        $this->assertNull($norte->fresh()->revoked_at, 'a manager cannot revoke another sede’s tablet');

        Livewire::actingAs($this->staff)->test(CounterTerminalsPage::class)->assertForbidden();
    }

    public function test_the_middleware_runs_on_both_stacks_after_the_session_starts(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $web = substr($bootstrap, (int) strpos($bootstrap, '$middleware->web(append: ['));
        $web = substr($web, 0, (int) strpos($web, ']);'));
        $this->assertStringContainsString('RecogniseCounterTerminal::class', $web, 'not on the web group');
        $this->assertStringNotContainsString('append(RecogniseCounterTerminal', $bootstrap, 'never the global stack');

        $panel = Filament::getPanel('admin')->getMiddleware();
        $start = array_search(StartSession::class, $panel, true);
        $mine = array_search(RecogniseCounterTerminal::class, $panel, true);
        $this->assertIsInt($mine, 'not on the panel stack');
        $this->assertGreaterThan($start, $mine, 'must run after the panel’s StartSession');
    }

    public function test_the_permission_is_catalogued_with_a_spanish_label(): void
    {
        $this->assertContains('terminals.manage', Permissions::ALL);
        $this->assertContains('terminals.manage', Permissions::for(Role::MANAGER));
        $this->assertNotContains('terminals.manage', Permissions::for(Role::STAFF));
        $label = Permissions::label('terminals.manage');
        $this->assertNotSame('terminals.manage', $label);
        $this->assertSame($label, __($label, [], 'es'));
    }

    public function test_the_store_can_never_be_a_terminal_home(): void
    {
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN]);
        $owner = $this->person(Role::OWNER, '1111', [$this->centro]);

        $this->expectException(\InvalidArgumentException::class);
        (new RegisterCounterTerminal)->handle($owner, $store, 'Almacén');
    }
}
