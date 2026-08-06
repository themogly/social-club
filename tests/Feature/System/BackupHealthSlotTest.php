<?php

namespace Tests\Feature\System;

use App\Enums\Role;
use App\Filament\Pages\SystemHealth as SystemHealthPage;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 180 — the health panel said backups were not configured. They are.
 *
 * `Salud del sistema` rendered a permanent "Copias de seguridad — Última copia: Sin configurar / Pendiente
 * de conectar una canalización de copias", fed by two Settings keys nothing ever wrote. With prompt 160
 * dropped — the owner handles backups on his own infrastructure and no backup mechanism belongs in this
 * application — nothing ever would, so the claim was permanent AND false. Backups exist; the application
 * has no visibility of them, which is a different statement and the far less damaging one.
 *
 * The section now states where responsibility sits and reports NO status. These tests exist mostly so the
 * placeholder cannot come back through a different door.
 */
class BackupHealthSlotTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    private function page(): string
    {
        return Livewire::actingAs($this->owner())->test(SystemHealthPage::class)->html();
    }

    public function test_the_page_no_longer_claims_backups_are_unconfigured(): void
    {
        $html = $this->page();

        // Re-adding the placeholder fails the suite — that is the point of asserting the ABSENCE.
        $this->assertStringNotContainsString(__('Sin configurar'), $html);
        $this->assertStringNotContainsString(__('Pendiente de conectar una canalización de copias.'), $html);
        $this->assertStringNotContainsString(__('Última copia'), $html);
        $this->assertStringNotContainsString(__('Última restauración'), $html);
    }

    public function test_it_states_where_responsibility_sits_without_reporting_a_status(): void
    {
        $html = $this->page();

        $this->assertStringContainsString(__('Copias de seguridad'), $html);
        $this->assertStringContainsString(__('Se gestionan fuera de la aplicación, en la infraestructura del club.'), $html);
        // It must not imply the application verified anything.
        $this->assertStringContainsString(__('Esta aplicación no las realiza ni comprueba su estado.'), $html);
    }

    public function test_every_other_section_is_untouched(): void
    {
        $html = $this->page();

        // This branch edits a SHARED view, so the other sections are asserted explicitly rather than assumed.
        // `Bajas de temporales` is deliberately absent from this list — it is conditional on
        // `$temporarySweep`, so asserting it unconditionally would test the fixture, not the view.
        foreach ([
            'Planificador', 'Barrido de caducidades', 'Retención de auditoría', 'Retención de mensajes',
            'Barrido de importaciones', 'Colas', 'Correo', 'Disco de documentos', 'Caché', 'Retención',
        ] as $section) {
            $this->assertStringContainsString(__($section), $html, "the $section section went missing");
        }
    }

    public function test_the_view_model_no_longer_exposes_a_backups_method(): void
    {
        // Do not leave a method returning nulls that nothing consumes.
        $this->assertFalse(
            method_exists(SystemHealth::class, 'backups'),
            'SystemHealth::backups() is back — it reports a status this application cannot know'
        );
    }

    public function test_nothing_in_the_application_reads_or_writes_the_placeholder_keys(): void
    {
        // By SEARCH, so the keys cannot return through a different door — a new view model, a command, a
        // settings row. The blade comment explaining the retirement is the one allowed mention.
        $offenders = [];

        foreach (['app', 'resources', 'database', 'config', 'routes'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'json'], true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach (['last_backup_at', 'last_restore_at'] as $key) {
                    // A mention inside the blade comment that records WHY they were retired is not a read.
                    if (str_contains($contents, $key) && ! str_contains($contents, 'Prompt 180')) {
                        $offenders[] = $file->getPathname().' → '.$key;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'the retired backup settings keys are referenced again');
    }

    public function test_no_backup_mechanism_was_added(): void
    {
        // The owner's decision (prompt 160 dropped) is that no backup mechanism belongs in this application.
        // This branch exists because of it, not in spite of it — so: no command, no scheduler entry.
        $commands = array_keys(app(Kernel::class)->all());

        foreach ($commands as $name) {
            $this->assertStringNotContainsString('backup', strtolower($name), "a backup command appeared: $name");
        }
    }

    public function test_the_page_is_still_owner_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        foreach ([Role::MANAGER, Role::STAFF] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role->value);

            $this->actingAs($user);
            $this->assertFalse(SystemHealthPage::canAccess(), "{$role->value} can reach Salud del sistema");
        }

        $this->actingAs($this->owner());
        $this->assertTrue(SystemHealthPage::canAccess());
    }
}
