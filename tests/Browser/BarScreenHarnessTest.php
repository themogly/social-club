<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 193 — writes the REAL, authed bar screen to storage/app/bar-screen.html for the Playwright pass
 * (`node tests/Browser/shoot-bar-screen.mjs`), which counts rows and measures the cart column.
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check.
 */
class BarScreenHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_bar_screen_for_the_screenshot_pass(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);

        $user = User::factory()->create(['name' => 'Club Owner']);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($location->id);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($location, 'POS-1', 10000);

        // Real-shaped catalogue: two-word names are the ones the reporter saw wrap.
        $catalogue = [
            ['Papel de fumar', 90], ['Refresco', 150], ['Mechero', 100], ['Snack salado', 200],
            ['Café solo', 120], ['Agua mineral', 100], ['Cerveza sin alcohol', 180], ['Bocadillo mixto', 350],
            ['Filtros de cartón', 80], ['Grinder metálico', 900], ['Bolsa hermética', 60], ['Zumo natural', 220],
        ];
        foreach ($catalogue as [$name, $price]) {
            Article::factory()->create([
                'organisation_id' => $org->id, 'location_id' => $location->id,
                'name' => $name, 'price_cents' => $price, 'stock' => 10, 'active' => true,
            ]);
        }

        // BOTH layouts. `articleLayout` is #[Session]-backed, so the choice survives a plain GET — which is
        // how the reporter was in list mode. Grid is the default and stays so.
        foreach (['list', 'grid'] as $layout) {
            session(['counter.bar.article_layout' => $layout]);
            $html = $this->render();
            file_put_contents(storage_path('app/bar-screen-'.$layout.'.html'), $html);

            $this->assertStringContainsString('data-product', $html);
            $this->assertStringContainsString('data-commit-action', $html);
        }
    }

    private function render(): string
    {
        // assertOk() FIRST: a Blade parse error renders a 500 page that contains the template source, so a
        // bare assertStringContainsString('data-product') passes against the exception page. It did.
        $html = $this->get(route('counter.bar'))->assertOk()->getContent();

        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }
        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        return $html;
    }
}
