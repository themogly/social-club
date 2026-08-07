<?php

namespace Tests\Feature\Lockdown;

use App\Actions\Lockdown\InitiateLockdown;
use App\Enums\Role;
use App\Filament\Pages\Seguridad;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 200 — the three lockdown SURFACES, which prompt 121 could only test as a mechanism.
 *
 * 121's own entry in DECISIONS closes with a *"Verification gap (owed — no browser here)"*: the counter panic
 * trigger, the Filament Seguridad page and the ordinary 503 screen were never seen. `PanicLockdownTest`
 * covers the mechanism thoroughly — audit-before-lock, idempotency, the three ways back, the drill, signed
 * document URLs dying — and none of that is what was owed.
 *
 * What was owed is what a person SEES, and a panic lockdown is used once, under stress, by someone who has
 * never used it before. These are the structural halves; the pixels are `tests/Browser/shoot-lockdown.mjs`.
 *
 * The load-bearing one is the 503: the whole design intent is that a locked-down club does not announce to
 * whoever is standing in the room that a lockdown was triggered. A page that says or implies it defeats the
 * feature, so what that page contains is asserted as TEXT, not eyeballed.
 */
class LockdownSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
        $this->org = Organisation::factory()->create(['name' => 'Asociación Ejemplo']);
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    // --- 1. The discreet counter trigger ----------------------------------------

    public function test_the_counter_offers_the_lockdown_trigger_to_a_holder(): void
    {
        $this->user(Role::STAFF); // holds lockdown.initiate (prompt 121, OVERNIGHT-DEFAULT)

        $html = $this->get(route('counter.checkin'))->assertOk()->getContent();

        $this->assertStringContainsString('data-counter-panic', (string) $html);
        $this->assertStringContainsString(__('Bloqueo de seguridad'), (string) $html);
        // It confirms first: an accidental trip closes the whole club.
        $this->assertStringContainsString(__('¿Activar el bloqueo de seguridad? Cerrará el club entero.'), (string) $html);
    }

    /**
     * ABSENT from the DOM, not hidden — a hidden control is still a control someone can reach.
     *
     * `PanicLockdownTest` already proves the ROUTE refuses a non-holder. This is the other half: they are
     * never shown it in the first place.
     */
    public function test_the_counter_trigger_is_absent_from_the_dom_without_the_permission(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $user->revokePermissionTo('lockdown.initiate');
        $user->roles()->detach();
        $user->givePermissionTo(['checkin.manage', 'members.view']);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->assertFalse($user->fresh()->can('lockdown.initiate'));

        $html = (string) $this->get(route('counter.checkin'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-counter-panic', $html);
        $this->assertStringNotContainsString(route('counter.panic'), $html);
        $this->assertStringNotContainsString(__('Bloqueo de seguridad'), $html);
    }

    // --- 2. The Seguridad page ---------------------------------------------------

    public function test_the_seguridad_page_renders_its_actions_for_a_manager(): void
    {
        $this->user(Role::MANAGER);

        Livewire::test(Seguridad::class)
            ->assertOk()
            ->assertActionExists('panic')
            ->assertActionExists('drill');
    }

    public function test_the_seguridad_page_is_refused_to_a_user_with_neither_permission(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);

        $this->assertFalse(Seguridad::canAccess());
        $this->get(Seguridad::getUrl())->assertForbidden();
    }

    /** End-drill appears only while a drill is running — the action follows the state, not the permission. */
    public function test_the_end_drill_action_appears_only_during_a_drill(): void
    {
        $manager = $this->user(Role::MANAGER);

        Livewire::test(Seguridad::class)->assertActionHidden('endDrill');

        (new InitiateLockdown)->handle($this->org, ['actor' => $manager, 'is_drill' => true]);

        Livewire::test(Seguridad::class)
            ->assertActionVisible('endDrill')
            ->assertActionHidden('drill'); // you cannot start one inside one
    }

    // --- 3. The 503, which is the one with a consequence -------------------------

    /**
     * What the page says to somebody who does not know what happened.
     *
     * Asserted as TEXT because that IS the guarantee: no "lockdown", no "blocked", no club name, no sede, no
     * member data, no operator name. A mundane maintenance notice and nothing else.
     */
    public function test_the_503_reads_as_a_mundane_outage_and_leaks_nothing(): void
    {
        $staff = $this->user(Role::STAFF);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Lucía', 'last_name' => 'García']);
        (new InitiateLockdown)->handle($this->org, ['actor' => $staff]);

        $response = $this->get(route('counter.checkin'));
        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '600');

        $body = (string) $response->getContent();

        // It says the mundane thing…
        $this->assertStringContainsString(__('Servicio no disponible temporalmente'), $body);

        // …and nothing else. Case-insensitive: a capitalised leak is still a leak.
        foreach (['lockdown', 'bloqueo', 'locked', 'cerrado', 'pánico', 'panic', 'simulacro', 'emergencia'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, "the 503 must not contain «{$forbidden}»");
        }

        // No club, no sede, no member, no operator.
        $this->assertStringNotContainsString($this->org->name, $body);
        $this->assertStringNotContainsString($this->location->name, $body);
        $this->assertStringNotContainsString($member->last_name, $body);
        $this->assertStringNotContainsString($staff->name, $body);
    }

    /** Every route is behind it EXCEPT the way back — which is the whole point of the way back. */
    public function test_only_the_reactivation_path_survives_a_real_lockdown(): void
    {
        $staff = $this->user(Role::STAFF);
        (new InitiateLockdown)->handle($this->org, ['actor' => $staff]);

        foreach ([route('counter.checkin'), route('counter.pos'), route('counter.till'), '/socio/login', '/'] as $url) {
            $this->get($url)->assertStatus(503);
        }

        // The reactivation route ANSWERS rather than 503ing — that is the whole point of the way back.
        // A bad token gets its own "this link is no longer valid" page (200), deliberately: a 404 would
        // tell a holder of a stolen link whether the token was ever real.
        $response = $this->get('/reactivar/not-a-real-token');
        $this->assertNotSame(503, $response->getStatusCode(), 'the way back must not be behind the gate');
        $response->assertOk();
    }

    /** The drill lets an owner through to observe it, and stops everyone else exactly like the real thing. */
    public function test_a_drill_passes_an_owner_and_stops_a_staff_user_and_a_visitor(): void
    {
        $owner = $this->user(Role::OWNER);
        (new InitiateLockdown)->handle($this->org, ['actor' => $owner, 'is_drill' => true]);

        // The owner observes it.
        $this->actingAs($owner)->get(Seguridad::getUrl())->assertOk();

        // Staff see the ordinary screen — the club experiences the drill as the real thing.
        $staff = User::factory()->create(['active' => true]);
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->location->id]);
        $this->actingAs($staff)->get(route('counter.checkin'))->assertStatus(503);

        // And so does a visitor with no session at all.
        auth()->logout();
        $this->get('/socio/login')->assertStatus(503);
    }
}
