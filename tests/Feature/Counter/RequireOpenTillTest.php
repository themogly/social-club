<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Http\Middleware\RequireOpenTill;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 236 — no open till, no counter. The open-till step is the counter's front door.
 *
 * The owner: *"if the till isn't open, make them do it before doing anything else."* Prompt 175's chain is
 * `sede → operator → till → member`, but only the POS and the Bar ever carried the till step in-page — the
 * hub, the door and Socios stopped at `sede → operator`, so an operator could reach the door and record a
 * member entering a club that was not trading, or start a sign-up, with no drawer open.
 *
 * This branch moves the till step OUT of every screen and into ONE guard, {@see RequireOpenTill}, appended
 * globally. This file is that guard's contract: what it gates, what it lets through, that it is a real
 * server-side refusal and not just a picture (CLAUDE.md), and that it never becomes a dead end — the open
 * screen is the way out, a second device gets "Continuar" not a second till, and closing the till re-blocks.
 *
 * The in-page side of the same change — that no screen draws a till card anymore — lives in
 * {@see CounterBlockingStatesTest}. This file owns the redirect.
 *
 * **Prompt 241 — read this before trusting a green here.** These redirect cases seed the counter session
 * IN-PROCESS (`session([...])` / the runGuard helper), so they prove the guard's LOGIC given a STARTED
 * session. They do NOT prove it fires on a real request: 236 shipped the guard on the GLOBAL stack, which
 * runs before StartSession, so on a real request the session was unstarted and every read returned null —
 * and these same in-process tests stayed green because the test process and the guard share one session-store
 * object. That the guard runs AFTER StartSession is enforced by {@see CounterGuardsRunAfterSessionTest}
 * (ordering + a structural guard over bootstrap/app.php); the real-lifecycle redirect is shown by the browser
 * harness `tests/Browser/shoot-till-guard.mjs` against a running server. The in-process-session false-green is
 * catalogued in DECISIONS (prompt 241).
 */
class RequireOpenTillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every route in the counter group, and how this guard must treat it. A planted counter route fails the
     * classification test below until it is added here — the whole point of a "guard for the class".
     */
    private const EXPECTED = [
        'counter' => 'gated',                            // the hub (prompt 205)
        'counter/pos' => 'gated',                        // the dispensary
        'counter/bar' => 'gated',                        // the bar
        'counter/checkin' => 'gated',                    // the door
        'counter/members' => 'gated',                    // Socios
        'counter/till' => 'allow',                       // the open screen — the destination
        'counter/location' => 'allow',                   // the sede switcher (POST)
        'counter/panic' => 'allow',                      // panic — NEVER gated (prompt 121)
        'counter/members/{member}/photo' => 'allow',     // identity-photo write (XHR, mid-serve)
        'counter/pos/receipt/{dispensation}' => 'allow', // read of a committed contribution
        'counter/bar/receipt/{order}' => 'allow',        // read of a committed sale
    ];

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    /** Signed in, at a sede, PIN-identified. NO till open. Returns the user. */
    private function operator(Role $role = Role::MANAGER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
        session(['counter.location_id' => $this->location->id]);

        return $user;
    }

    /** The counter session keys the middleware reads, for HTTP requests that must carry them. */
    private function sessionKeys(): array
    {
        return [
            'counter.location_id' => $this->location->id,
            'counter.operator_id' => CounterOperator::id(),
        ];
    }

    private function openTill(): TillSessionModel
    {
        return (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    /** Run the guard alone over a path (no HTTP stack), returning its response. Session is already set up. */
    private function runGuard(string $uri, string $method = 'GET'): \Symfony\Component\HttpFoundation\Response
    {
        return (new RequireOpenTill)->handle(
            Request::create('/'.$uri, $method),
            fn (): Response => new Response('ok', 200),
        );
    }

    // --- The guard for the class -------------------------------------------------

    /**
     * Every counter route is classified — allowlisted or gated — and the guard's runtime behaviour matches
     * the declared class. A route planted in the counter group with no entry in EXPECTED fails here; so does
     * a classified route that no longer exists, or one whose runtime treatment disagrees with its label.
     *
     * Fails against `main`, which has no guard at all: the hub, the door and Socios do not redirect there.
     */
    public function test_every_counter_route_is_classified_and_the_guard_agrees(): void
    {
        $actual = collect(Route::getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => $uri === 'counter' || Str::startsWith($uri, 'counter/'))
            ->unique()->values();

        // 1) No counter route is unaccounted for — a planted route fails until it is classified…
        foreach ($actual as $uri) {
            $this->assertArrayHasKey($uri, self::EXPECTED, "counter route '{$uri}' is not classified in RequireOpenTillTest::EXPECTED");
        }
        // 2) …and the map has not gone stale against the real table.
        foreach (array_keys(self::EXPECTED) as $uri) {
            $this->assertTrue($actual->contains($uri), "classified route '{$uri}' no longer exists");
        }

        // 3) The guard's runtime behaviour matches each declared class: sede + operator set, no till open.
        $this->operator();
        foreach (self::EXPECTED as $uri => $class) {
            $response = $this->runGuard($uri);

            if ($class === 'allow') {
                $this->assertTrue(RequireOpenTill::isAllowedPath($uri), "{$uri} should be on the allowlist");
                $this->assertSame(200, $response->getStatusCode(), "{$uri} is allowlisted and must pass through");
            } else {
                $this->assertFalse(RequireOpenTill::isAllowedPath($uri), "{$uri} must not be on the allowlist");
                $this->assertTrue($response->isRedirect(), "{$uri} must redirect when no till is open");
                $this->assertStringContainsString('/counter/till', (string) $response->headers->get('Location'), "{$uri} must redirect to the open-till screen");
            }
        }
    }

    /** The end-to-end proof, through the real HTTP stack: the hub, the door and Socios all redirect. */
    public function test_the_hub_the_door_and_socios_redirect_when_no_till_is_open(): void
    {
        $this->operator();

        foreach (['counter', 'counter/pos', 'counter/bar', 'counter/checkin', 'counter/members'] as $path) {
            $this->withSession($this->sessionKeys())
                ->get('/'.$path)
                ->assertRedirect(route('counter.till'));
        }
    }

    /** The first two steps of 175's chain are still in-page: with no sede or no operator, the guard defers. */
    public function test_the_guard_defers_until_a_sede_and_an_operator_are_set(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);

        // No sede, no operator adopted → the page (the sede chooser / PIN surface) must show, not a redirect.
        $this->assertSame(200, $this->runGuard('counter/pos')->getStatusCode());

        // Sede but still no operator → the PIN surface owns this step; the guard still defers.
        session(['counter.location_id' => $this->location->id]);
        $this->assertSame(200, $this->runGuard('counter/pos')->getStatusCode());

        // Both set, no till → NOW it gates.
        CounterOperator::set($user);
        $this->assertTrue($this->runGuard('counter/pos')->isRedirect());
    }

    // --- The intended-URL round trip --------------------------------------------

    /** A gated GET stashes where it was headed; opening the till returns the operator there. */
    public function test_a_gated_screen_stashes_its_url_and_open_returns_there(): void
    {
        $this->operator();

        $this->withSession($this->sessionKeys())
            ->get('/counter/pos')
            ->assertRedirect(route('counter.till'));

        // The guard remembered the destination.
        $this->assertSame(route('counter.pos'), session(RequireOpenTill::INTENDED_KEY));

        // Opening the till consumes it and lands the operator back on the POS.
        Livewire::test(TillSession::class)
            ->set('floatInput', '100,00')
            ->call('open')
            ->assertRedirect(route('counter.pos'));

        $this->assertNull(session(RequireOpenTill::INTENDED_KEY), 'the intended URL must be consumed, not left to fire again');
    }

    /** With nothing stashed, opening the till lands on the operator's own counter landing, never an error. */
    public function test_open_falls_back_to_the_landing_when_nothing_was_stashed(): void
    {
        $this->operator();

        Livewire::test(TillSession::class)
            ->set('floatInput', '100,00')
            ->call('open')
            ->assertRedirect(route('counter.home'));
    }

    // --- The allowlist is reachable without a till ------------------------------

    /**
     * The paths that must answer without a till: the open screen itself, the sede switcher, panic, the
     * lockdown lift, the photo write and every Livewire write (the PIN pad, the lock, open() itself).
     * A device that could not reach these when the till is closed could never OPEN the till, lock, switch
     * sede, or survive a robbery.
     */
    public function test_the_allowlist_is_reachable_without_a_till(): void
    {
        $this->operator();

        // The destination renders, rather than redirecting to itself.
        $this->assertSame(200, $this->runGuard('counter/till')->getStatusCode());

        foreach (['counter/location', 'counter/panic', 'counter/members/01ABC/photo'] as $path) {
            $this->assertSame(200, $this->runGuard($path, 'POST')->getStatusCode(), $path.' must not be gated');
        }

        // Livewire writes (open(), the PIN pad, the lock) and the lockdown lift are never this guard's business.
        // Livewire's REAL endpoint (`livewire-<hash>/update`, prompt 254), not the non-existent `livewire/update`.
        $this->assertFalse(RequireOpenTill::guardsPath(ltrim(app('livewire')->getUpdateUri(), '/')));
        $this->assertFalse(RequireOpenTill::guardsPath('reactivar/some-token'));
    }

    /**
     * The redirect never lands on a wall: the open screen a redirected operator reaches shows the ONE action
     * that resolves the block — opening the till — so the front door is the remedy, not a dead end. Proven for
     * STAFF, the floor role that gets redirected most, and which holds `till.open` (Permissions.php).
     */
    public function test_the_open_screen_is_the_remedy_the_redirect_lands_on(): void
    {
        $this->operator(Role::STAFF);

        $html = Livewire::test(TillSession::class)->html();

        $this->assertStringContainsString('data-till-open-screen', $html);
        $this->assertStringContainsString('data-till-open-action', $html);
    }

    // --- The gate is not a picture: server-side refusals -------------------------

    /**
     * The door's server side. `RequireOpenTill` redirects the browser away from the door, but a crafted
     * Livewire post reaches `checkIn()` directly (the update endpoint is not a `counter/*` path). The write
     * must refuse without a till — a member recorded as present at a club that is not trading is the hole.
     */
    public function test_the_door_refuses_entry_without_an_open_till(): void
    {
        $this->operator();
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
        ]);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->call('checkIn')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Abre una caja en esta sede antes de continuar.'));

        $this->assertSame(0, CheckIn::query()->count());
    }

    /** The counter sign-up's server side: starting an alta is counter work and refuses without a till. */
    public function test_the_counter_signup_refuses_to_start_without_an_open_till(): void
    {
        $this->operator();

        Livewire::test(MembershipCounter::class)
            ->call('handOverForAlta')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Abre una caja en esta sede antes de continuar.'));

        $this->assertSame(0, MemberApplication::query()->withoutGlobalScopes()->count());
    }

    /**
     * The one carve-out the prompt is explicit about: the APPLICANT's own emailed form is NOT counter work.
     * Somebody finishing a `socio/*` invite at midnight has no till and no counter session, and must never be
     * bounced to an open-till screen they cannot reach. The guard does not touch socio paths, and the form loads.
     */
    public function test_the_applicant_route_is_never_gated_by_the_till(): void
    {
        // No operator, no sede, NO till anywhere — a member at home with an emailed link.
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', 'midnight-token'),
            'invite_token' => 'midnight-token',
            'invite_expires_at' => now()->addDays(14),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);

        $this->get($application->inviteUrl())->assertOk()->assertSee(__('Enviar solicitud'));

        // And structurally: this guard's business is counter paths only.
        $this->assertFalse(RequireOpenTill::guardsPath('socio/solicitud/midnight-token'));
        $this->assertFalse(RequireOpenTill::guardsPath('socio'));
    }

    // --- Never a dead end -------------------------------------------------------

    /**
     * Two devices, one drawer. Device B is sent to the till with no drawer open; device A opens the sede's
     * shared till first. Device B must not be asked to open a SECOND one — it gets "Continuar" to where it
     * was heading, and taking it lands there.
     */
    public function test_two_devices_one_drawer_the_second_gets_continue_not_a_second_till(): void
    {
        $this->operator();
        session([RequireOpenTill::INTENDED_KEY => route('counter.pos')]); // B was redirected off the POS

        $this->openTill(); // device A opens the sede's shared drawer

        $component = Livewire::test(TillSession::class);
        $html = $component->html();

        $this->assertStringNotContainsString('data-till-open-screen', $html, 'B must not be shown a second open form');
        $this->assertStringContainsString('data-till-continue-action', $html);

        $component->call('continueToIntended')->assertRedirect(route('counter.pos'));
        $this->assertSame(1, TillSessionModel::query()->withoutGlobalScopes()->count(), 'no second till was opened');
    }

    /** Closing the till re-blocks the counter: the next counter request redirects to the open screen again. */
    public function test_closing_the_till_reblocks_the_counter(): void
    {
        $user = $this->operator();
        $session = $this->openTill();

        // Open till → the POS passes the guard.
        $this->assertSame(200, $this->runGuard('counter/pos')->getStatusCode());

        (new CloseTill)->handle($session, 10000, $user); // blind count == float, variance 0

        // Closed → the counter gates again.
        $this->assertTrue($this->runGuard('counter/pos')->isRedirect());
        $this->assertStringContainsString('/counter/till', (string) $this->runGuard('counter/pos')->headers->get('Location'));
    }
}
