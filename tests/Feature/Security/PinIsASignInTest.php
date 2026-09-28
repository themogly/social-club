<?php

namespace Tests\Feature\Security;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Filament\Pages\RolesPermissions;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\PanelIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 267 (Ben chose Option B) — the PIN IS a sign-in.
 *
 * Reported from the live tablet: on an owner-logged tablet, with a STAFF PIN at the counter, the counter applied staff
 * permissions (255) but the admin panel still ran as the OWNER — `/users`, `/roles-y-permisos` and `/audit-logs` all 200.
 * A staff member could open Roles y permisos and grant themselves anything, recorded as the owner. Now a valid PIN signs
 * the session in as that person, and a counter session with nobody identified has no panel either. Real HTTP requests.
 */
class PinIsASignInTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->owner = $this->user(Role::OWNER, '1111');
        $this->manager = $this->user(Role::MANAGER, '2222');
        $this->staff = $this->user(Role::STAFF, '3333');
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function user(Role $role, string $pin): User
    {
        $user = User::factory()->create(['pin' => Hash::make($pin), 'active' => true]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    /** The tablet, signed in once as `$device`, sede chosen, nobody at the PIN yet. */
    private function tablet(User $device): void
    {
        $this->actingAs($device);
        $this->post(route('counter.location'), ['location_id' => $this->location->id]);
        CounterOperator::clear();
    }

    /** The PIN, then the once-a-shift password (post-296 fix 3) — these tests are about who the panel belongs to. */
    private function pin(string $pin): TestResponse
    {
        $response = $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'),
            ['operatorPin' => $pin], [['unlockOperator']]);
        if (Auth::user() instanceof User) {
            PanelIdentity::confirmed(Auth::user());
        }

        return $response;
    }

    private function assertSentToTheCounter(string $url): void
    {
        $response = $this->get($url);
        $response->assertRedirect(route('counter.home'));
    }

    // --- 1. The reported case ---------------------------------------------------------------------------------

    public function test_a_staff_pin_on_an_owner_tablet_does_not_leave_the_owners_panel_open(): void
    {
        $this->tablet($this->owner);
        $this->pin('3333')->assertOk();

        foreach (['/users', RolesPermissions::getUrl(), '/audit-logs'] as $url) {
            $this->assertSentToTheCounter($url);
        }
        $this->assertAuthenticatedAs($this->staff);
        $this->assertStringNotContainsString('data-counter-admin-link', (string) $this->get(route('counter.home'))->getContent());
    }

    // --- 2. Manager PIN → the manager's panel ------------------------------------------------------------------

    public function test_a_manager_pin_gets_the_managers_panel_not_the_owners(): void
    {
        $this->tablet($this->owner);
        $this->pin('2222')->assertOk();

        $this->assertAuthenticatedAs($this->manager);
        $this->get('/')->assertOk();                                    // the manager has the panel…
        $this->get(RolesPermissions::getUrl())->assertForbidden();      // …but not the owner's pages
        $this->get('/users')->assertForbidden();
    }

    // --- 3. Owner PIN → the owner's panel ----------------------------------------------------------------------

    public function test_an_owner_pin_on_a_staff_tablet_gets_the_owners_panel(): void
    {
        $this->tablet($this->staff);
        $this->assertSentToTheCounter('/'); // a staff login has no panel

        $this->pin('1111')->assertOk();

        $this->assertAuthenticatedAs($this->owner);
        $this->get(RolesPermissions::getUrl())->assertOk();
    }

    // --- 4. Locked means locked ---------------------------------------------------------------------------------

    public function test_after_an_idle_lock_or_a_person_switch_the_panel_is_closed(): void
    {
        $this->tablet($this->owner);
        $this->pin('2222')->assertOk();
        $this->get('/')->assertOk();

        // The idle timer's lock.
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), calls: [['__dispatch', ['counter-lock', []]]])->assertOk();
        $this->assertNull(CounterOperator::id());
        $this->assertSentToTheCounter('/');
        $this->assertSentToTheCounter('/members');

        // A new PIN reopens it for that person.
        $this->pin('2222')->assertOk();
        $this->get('/')->assertOk();

        // "Cambiar de persona" with no new PIN closes it again.
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), calls: [['__dispatch', ['counter-switch-operator', []]]])->assertOk();
        $this->assertSentToTheCounter('/');
    }

    public function test_a_fresh_counter_session_with_no_pin_has_no_panel(): void
    {
        $this->tablet($this->owner); // the owner's own login, at the counter, nobody identified yet

        $this->assertSentToTheCounter('/users');
    }

    public function test_a_password_login_that_never_used_the_counter_keeps_its_panel(): void
    {
        // The owner on their own phone: signed in with a password, never touched the counter.
        $this->actingAs($this->owner)->get('/')->assertOk();
    }

    // --- 5. State carried over ----------------------------------------------------------------------------------

    public function test_the_sede_and_the_basket_survive_a_pin_switch(): void
    {
        $this->tablet($this->owner);
        $this->pin('3333')->assertOk();
        session(['counter.basket.probe' => ['kept' => true]]);

        $this->pin('2222')->assertOk();

        $this->assertAuthenticatedAs($this->manager);
        $this->assertSame($this->location->id, session('counter.location_id'));
        $this->assertSame(['kept' => true], session('counter.basket.probe'));
    }

    // --- 6. The throttle still applies ----------------------------------------------------------------------------

    public function test_wrong_pins_count_and_a_lockout_blocks_the_switch(): void
    {
        $this->tablet($this->staff);

        foreach (range(1, 10) as $i) {
            $this->pin('0000');
        }

        $this->pin('1111')->assertOk();
        $this->assertAuthenticatedAs($this->staff); // still the tablet's login — the lockout held
        $this->assertNull(CounterOperator::id());
    }

    // --- 7. Session fixation, remember-me and the audit ----------------------------------------------------------

    public function test_every_switch_regenerates_the_session_and_drops_the_original_remember_cookie(): void
    {
        $this->tablet($this->owner);
        $before = session()->getId();

        $response = $this->pin('3333')->assertOk();

        $this->assertNotSame($before, session()->getId(), 'the session id survived a PIN sign-in');
        $recaller = Auth::guard('web')->getRecallerName();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $recaller);
        $this->assertNotNull($cookie, 'the owner\'s remember-me cookie is not cleared');
        $this->assertTrue($cookie->isCleared());

        $audit = AuditLog::query()->where('action', 'counter.operator.signed_in')->sole();
        $this->assertSame($this->staff->id, $audit->actor_id);
        $this->assertSame($this->owner->id, $audit->after['from_user_id']);
        $this->assertSame($this->location->id, $audit->after['location_id']);
    }
}
