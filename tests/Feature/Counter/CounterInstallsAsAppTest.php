<?php

namespace Tests\Feature\Counter;

use App\Enums\Role;
use App\Enums\SettingType;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\OrganisationIdentity;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 290 — the counter installs as its own app on the tablet (no browser bar): a second manifest, `Mostrador`, beside
 * the member area's. Scope `/` so login, the lockdown page and the panel stay inside the app; no service worker, because
 * the counter shows member data and must never be served from a cache.
 */
class CounterInstallsAsAppTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::MANAGER->value);
        $this->user->locations()->sync([$this->location->id]);
    }

    // 1 -------------------------------------------------------------------------------------------------------------

    public function test_the_manifest_is_the_counter_app_in_the_clubs_name(): void
    {
        $response = $this->actingAs($this->user)->get('/counter.webmanifest')->assertOk();

        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $manifest = $response->json();
        $club = OrganisationIdentity::tradingName();

        $this->assertSame('/counter', $manifest['id']);
        $this->assertSame('/counter', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('any', $manifest['orientation']);
        $this->assertSame($club.' · Mostrador', $manifest['name']);
        $this->assertSame('Mostrador', $manifest['short_name']);
        $this->assertSame('es', $manifest['lang']);
        $this->assertNotEmpty($manifest['theme_color']);
        $this->assertNotEmpty($manifest['background_color']);
        $srcs = array_column($manifest['icons'], 'src');
        $this->assertContains('/counter-icons/icon-192.png', $srcs);
        $this->assertContains('/counter-icons/icon-512.png', $srcs);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        foreach ($srcs as $src) {
            $this->assertFileExists(public_path(ltrim($src, '/')));
            $this->assertFileNotEquals(public_path(str_replace('counter-icons', 'socio-icons', ltrim($src, '/'))), public_path(ltrim($src, '/')), 'the counter icon must differ from the member app’s');
        }
    }

    public function test_the_manifest_is_not_a_public_page(): void
    {
        $this->get('/counter.webmanifest')->assertRedirect();
    }

    // 2 -------------------------------------------------------------------------------------------------------------

    public function test_an_english_club_gets_an_english_manifest(): void
    {
        Settings::set('default_locale', 'en', SettingType::STRING);

        $manifest = $this->actingAs($this->user)->get('/counter.webmanifest')->assertOk()->json();

        $this->assertSame(OrganisationIdentity::tradingName().' · Counter', $manifest['name']);
        $this->assertSame('Counter', $manifest['short_name']);
        $this->assertSame('en', $manifest['lang']);
    }

    // 3 -------------------------------------------------------------------------------------------------------------

    public function test_only_the_counter_layout_links_the_counter_manifest(): void
    {
        CounterOperator::set($this->user);
        session(['counter.location_id' => $this->location->id]);
        $html = (string) $this->actingAs($this->user)->get('/counter/till')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="manifest" href="/counter.webmanifest" crossorigin="use-credentials">', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable" content="yes"', $html);
        $this->assertStringContainsString('name="mobile-web-app-capable" content="yes"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="/counter-icons/apple-touch-icon-180.png"', $html);
        $this->assertMatchesRegularExpression('/name="theme-color"[^>]+prefers-color-scheme: dark/', $html);

        foreach (['components/layouts/socio.blade.php'] as $layout) {
            $this->assertStringNotContainsString('counter.webmanifest', (string) file_get_contents(resource_path('views/'.$layout)));
        }
        $panel = (string) $this->actingAs($this->user)->get('/')->getContent();
        $this->assertStringNotContainsString('counter.webmanifest', $panel);
    }

    // 4 -------------------------------------------------------------------------------------------------------------

    public function test_no_counter_page_registers_a_service_worker_that_caches(): void
    {
        CounterOperator::set($this->user);
        session(['counter.location_id' => $this->location->id]);
        $html = (string) $this->actingAs($this->user)->get('/counter/till')->assertOk()->getContent();

        $this->assertStringNotContainsString("register('/sw.js'", $html);
        $this->assertStringNotContainsString('register("/sw.js"', $html);
        $this->assertStringNotContainsString('sw.js', (string) file_get_contents(resource_path('views/components/layouts/counter.blade.php')));

        if (is_file(public_path('counter-sw.js'))) {
            $this->assertStringNotContainsString('caches.', (string) file_get_contents(public_path('counter-sw.js')));
        }
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_the_install_button_is_translated_and_hides_when_installed(): void
    {
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $this->assertSame('Install as app', $en['Instalar como app'] ?? null);

        $bar = (string) file_get_contents(resource_path('views/components/counter/top-bar.blade.php'));
        $this->assertStringContainsString('data-counter-install', $bar);
        $this->assertStringContainsString("matchMedia('(display-mode: standalone)')", $bar);
        $this->assertStringContainsString('beforeinstallprompt', (string) file_get_contents(resource_path('js/app.js')));
    }
}
