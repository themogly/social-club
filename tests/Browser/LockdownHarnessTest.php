<?php

namespace Tests\Browser;

use App\Actions\Lockdown\InitiateLockdown;
use App\Enums\Role;
use App\Filament\Pages\Seguridad;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Prompt 200 — writes the three lockdown SURFACES to storage/app/lockdown-*.html for the screenshot pass
 * (`node tests/Browser/shoot-lockdown.mjs`).
 *
 * Prompt 121 closed with a verification gap it could not close: *"no browser here"*. A panic lockdown is used
 * once, under stress, by someone who has never used it before — every one of these is about what a person
 * sees at that moment, and the 503 in particular only earns its design if a stranger reads it as an ordinary
 * outage.
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check. The
 * behavioural assertions live in `tests/Feature/Lockdown/LockdownSurfacesTest`; this file writes artifacts.
 */
class LockdownHarnessTest extends TestCase
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

    private function actAs(Role $role): User
    {
        $user = User::factory()->create(['name' => 'Marta Ruiz', 'active' => true]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    /** A user who may work the counter but may NOT trip a lockdown. */
    private function actAsNonHolder(): User
    {
        $user = User::factory()->create(['name' => 'Ana Pérez', 'active' => true]);
        $user->locations()->sync([$this->location->id]);
        $user->givePermissionTo(['checkin.manage', 'members.view']);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    /**
     * @param  'counter'|'panel'|'none'  $stylesheet  which built CSS this surface actually loads
     *
     * Prompt 176's fidelity lesson, and it bites in BOTH directions. A COUNTER page loads
     * `resources/css/app.css` and nothing else, so inlining the Filament panel theme after it corrupts the
     * cascade. A PANEL page is the opposite: it is styled almost entirely by `theme-*.css`, and inlining
     * only app.css photographs an unstyled document — which is exactly what the first run of this harness
     * produced for the Seguridad page. The 503 carries its own inline <style> and needs neither.
     */
    private function write(string $name, string $html, string $stylesheet = 'counter'): void
    {
        $pattern = match ($stylesheet) {
            'panel' => 'build/assets/theme-*.css',
            'counter' => 'build/assets/app-*.css',
            default => null,
        };

        $css = '';
        foreach ($pattern !== null ? (glob(public_path($pattern)) ?: []) : [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '' && str_contains($html, '</head>')) {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/lockdown-'.$name.'.html'), $html);
    }

    public function test_it_writes_the_three_lockdown_surfaces_for_the_screenshot_pass(): void
    {
        // Artifacts first, assertions after — the same convention as the other harnesses.

        // 1a. The counter overflow for a HOLDER (staff hold lockdown.initiate — prompt 121).
        $this->actAs(Role::STAFF);
        $holder = (string) $this->get(route('counter.checkin'))->getContent();
        $this->write('counter-holder', $holder);

        // 1b. …and for someone who may not trip it.
        $this->actAsNonHolder();
        $nonHolder = (string) $this->get(route('counter.checkin'))->getContent();
        $this->write('counter-non-holder', $nonHolder);

        // 2. The Seguridad page, for a manager who may rehearse and trip.
        $manager = $this->actAs(Role::MANAGER);
        $seguridad = (string) $this->get(Seguridad::getUrl())->getContent();
        $this->write('seguridad', $seguridad, 'panel');

        // 3. The 503, as a stranger meets it: locked for real, then any route.
        (new InitiateLockdown)->handle($this->org, ['actor' => $manager]);
        $unavailable = (string) $this->get(route('counter.checkin'))->getContent();
        $this->write('unavailable', $unavailable, 'none');

        // --- assertions ---

        $this->assertStringContainsString('data-counter-panic', $holder);
        $this->assertStringNotContainsString('data-counter-panic', $nonHolder);
        $this->assertStringContainsString(__('Seguridad'), $seguridad);
        $this->assertStringContainsString(__('Servicio no disponible temporalmente'), $unavailable);
        $this->assertStringNotContainsString($this->org->name, $unavailable);
    }
}
