<?php

namespace Tests\Browser;

use App\Enums\Role;
use App\Filament\Pages\SystemHealth;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 180 — writes the Salud del sistema page to storage/app/system-health.html for the screenshot pass
 * (`node tests/Browser/shoot-backup-slot.mjs`).
 *
 * Note the CSS rule is the OPPOSITE of the counter harnesses: this is a Filament PANEL page, so `theme-*.css`
 * is exactly what it loads and `app-*.css` is the one that does not belong. Prompt 176's lesson is not
 * "always app.css" — it is "inline what the page itself loads, and nothing else".
 */
class BackupSlotHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_health_page_for_the_screenshot_pass(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);

        $html = $this->get(SystemHealth::getUrl())->getContent();

        $css = '';
        foreach (glob(public_path('build/assets/theme-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/system-health.html'), $html);

        // The structural half, so this guards in CI too: the claim is gone, the statement of fact is there.
        $this->assertStringContainsString(__('Copias de seguridad'), $html);
        $this->assertStringContainsString(__('Se gestionan fuera de la aplicación, en la infraestructura del club.'), $html);
        $this->assertStringContainsString(__('Esta aplicación no las realiza ni comprueba su estado.'), $html);
        // …and none of the retired claims came back.
        foreach (['Sin configurar', 'Pendiente de conectar', 'Última copia', 'Última restauración'] as $retired) {
            $this->assertStringNotContainsString($retired, $html, "the page still says \"$retired\"");
        }
    }
}
