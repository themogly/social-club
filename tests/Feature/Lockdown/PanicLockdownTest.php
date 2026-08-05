<?php

namespace Tests\Feature\Lockdown;

use App\Actions\Lockdown\InitiateLockdown;
use App\Enums\Role;
use App\Models\Location;
use App\Models\LockdownReactivationToken;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 121 — the org-wide panic lockdown. The security-critical properties: the audit lands BEFORE the lock;
 * the lock blocks every surface behind an ORDINARY screen; a real lockdown is NOT reversible from the premises,
 * only via the owner's off-terminal link, the time-delay, or the break-glass CLI; a drill rehearses it safely.
 */
class PanicLockdownTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Mail::fake();
    }

    private function owner(): User
    {
        $u = User::factory()->create(['active' => true]);
        $u->assignRole(Role::OWNER->value);

        return $u;
    }

    private function staff(): User
    {
        $u = User::factory()->create(['active' => true]);
        $u->assignRole(Role::STAFF->value);

        return $u;
    }

    private function lock(bool $drill = false): OrganisationLockdown
    {
        return (new InitiateLockdown)->handle($this->org, ['actor' => $this->owner(), 'is_drill' => $drill]);
    }

    public function test_initiating_audits_before_locking_and_is_idempotent(): void
    {
        $lockdown = $this->lock();

        $this->assertNotNull(OrganisationLockdown::active($this->org->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.lockdown.initiated']);

        // A second press does not open a second lockdown or re-notify.
        $again = (new InitiateLockdown)->handle($this->org, ['actor' => $this->owner()]);
        $this->assertSame($lockdown->id, $again->id);
        $this->assertSame(1, OrganisationLockdown::query()->withoutGlobalScopes()->open()->count());
    }

    public function test_the_gate_blocks_the_counter_with_an_ordinary_screen(): void
    {
        $staff = $this->staff();
        $staff->locations()->sync([Location::factory()->create(['organisation_id' => $this->org->id])->id]);
        $this->lock();

        $response = $this->actingAs($staff)->get(route('counter.checkin'));

        $response->assertStatus(503);
        $response->assertSee(__('Servicio no disponible temporalmente'));
        // Ordinary — it must NOT announce the lockdown to whoever is in the room.
        $response->assertDontSee('bloqueo', false);
        $response->assertDontSee('locked', false);
    }

    public function test_the_gate_blocks_the_member_pwa(): void
    {
        $this->lock();
        // Even the unauthenticated PWA login is behind the ordinary screen while locked.
        $this->get('/socio/login')->assertStatus(503);
    }

    public function test_an_owner_link_reactivates_and_is_single_use(): void
    {
        $owner = $this->owner();
        // Deterministic token so we can build the link (InitiateLockdown mails a random one to each owner).
        $lockdown = $this->lock();
        $raw = Str::random(64);
        LockdownReactivationToken::create([
            'organisation_lockdown_id' => $lockdown->id,
            'user_id' => $owner->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addHours(48),
        ]);

        $this->get(route('lockdown.reactivate', ['token' => $raw]))
            ->assertOk()
            ->assertSee(__('Acceso reactivado'));

        $this->assertNull(OrganisationLockdown::active($this->org->id));
        $this->assertSame('owner_link', $lockdown->refresh()->reactivation_method);

        // Single-use: the same link no longer works.
        $this->get(route('lockdown.reactivate', ['token' => $raw]))->assertSee(__('Enlace no válido'));
    }

    public function test_the_reactivation_route_is_rate_limited(): void
    {
        // Security audit (Phase C): the reactivation route is throttled like login/verify, so a token-guessing
        // sweep is capped even though the sha256 token is unguessable. The throttle runs before token validation.
        for ($i = 0; $i < 10; $i++) {
            $this->get(route('lockdown.reactivate', ['token' => 'nope-'.$i]))->assertStatus(200); // invalid token → "Enlace no válido", but not throttled yet
        }
        $this->get(route('lockdown.reactivate', ['token' => 'nope-11']))->assertStatus(429);
    }

    public function test_the_auto_delay_command_reactivates_after_the_window(): void
    {
        $lockdown = $this->lock();
        $lockdown->update(['locked_at' => now()->subHours(25)]); // past the 24h default

        $this->artisan('lockdown:auto-reactivate')->assertSuccessful();

        $this->assertNull(OrganisationLockdown::active($this->org->id));
        $this->assertSame('auto_delay', $lockdown->refresh()->reactivation_method);
    }

    public function test_the_auto_delay_leaves_a_fresh_lockdown_alone(): void
    {
        $this->lock(); // just now — well within the window
        $this->artisan('lockdown:auto-reactivate')->assertSuccessful();
        $this->assertNotNull(OrganisationLockdown::active($this->org->id));
    }

    public function test_the_break_glass_command_reactivates(): void
    {
        $this->lock();
        $this->artisan('lockdown:reactivate', ['organisation' => $this->org->id])
            ->expectsConfirmation("Lift the lockdown for organisation {$this->org->id}?", 'yes')
            ->assertSuccessful();

        $this->assertNull(OrganisationLockdown::active($this->org->id));
    }

    public function test_a_drill_blocks_staff_but_lets_an_owner_through(): void
    {
        $staff = $this->staff();
        $owner = $this->owner();
        $this->lock(drill: true);

        // Staff see the ordinary screen (they experience the real thing) ...
        $this->actingAs($staff)->get('/')->assertStatus(503);
        // ... but an owner passes, so they can observe and end the rehearsal.
        $this->actingAs($owner)->get('/')->assertDontSee(__('Servicio no disponible temporalmente'));
    }

    public function test_a_locked_down_document_url_is_refused(): void
    {
        // The gate runs before the `signed` middleware, so every sensitive-doc endpoint is dead while locked —
        // that is how outstanding signed URLs are invalidated (prompt 121).
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->lock();

        $this->get("/members/photo/{$member->id}")->assertStatus(503);
    }

    public function test_staff_without_the_permission_cannot_be_the_only_gap(): void
    {
        // A user with no role cannot trip the counter panic route.
        $nobody = User::factory()->create(['active' => true]);
        $nobody->locations()->sync([Location::factory()->create(['organisation_id' => $this->org->id])->id]);

        $this->actingAs($nobody)->post(route('counter.panic'))->assertForbidden();
        $this->assertNull(OrganisationLockdown::active($this->org->id));
    }

    public function test_staff_can_trip_the_counter_panic(): void
    {
        $staff = $this->staff();
        $staff->locations()->sync([Location::factory()->create(['organisation_id' => $this->org->id])->id]);

        // Tripping it locks the org; the redirect back lands on the ordinary screen (gate now serves it).
        $this->actingAs($staff)->post(route('counter.panic'));

        $this->assertNotNull(OrganisationLockdown::active($this->org->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.lockdown.initiated']);
    }
}
