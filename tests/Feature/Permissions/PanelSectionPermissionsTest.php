<?php

namespace Tests\Feature\Permissions;

use App\Enums\Role;
use App\Filament\Pages\Auth\ConfirmIdentity;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Glosario;
use App\Filament\Pages\Manual;
use App\Filament\Pages\RolesPermissions;
use App\Filament\Pages\Seguridad;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 309 — a staff account given the panel saw *Socios, Solicitudes, Cajas, Documentos generados, Seguridad*: each
 * page's gate was a COUNTER permission (the member lookup, a sign-up at the counter, the drawer, one member's documents,
 * the panic button), so granting the panel opened every till's Z reports and every member's documents with no switch to
 * turn them off. Each section now has its own `panel.*` permission, paired with the action permission; owner and manager
 * keep everything; staff get none. A structural guard: `panel.access` plus EVERY counter permission opens nothing but the
 * dashboard, the Manual, the Glossary (and the way back to the counter).
 */
class PanelSectionPermissionsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private const SECTIONS = ['panel.members', 'panel.applications', 'panel.tills', 'panel.member_documents', 'panel.security', 'panel.stock_count'];

    /** Always there for anyone with the panel: the home page, the way back to the counter, the help. */
    private const ALWAYS = ['Panel', 'Mostrador', 'Manual', 'Glosario'];

    /**
     * Counter-checked permissions whose panel page IS the same job, so they are not paired (DECISIONS, prompt 309):
     * registering a tablet at the counter and revoking it on *Mostradores registrados* are one job (terminals.manage);
     * choosing a counter's sede and managing the sede on *Sedes* are one authority (settings.manage.location). Both are
     * manager grants, held by no STAFF by default.
     */
    private const SAME_JOB = ['terminals.manage', 'settings.manage.location'];

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user->fresh();
    }

    /** @return list<string> the sidebar as this person sees it */
    private function navigation(User $user): array
    {
        app()->forgetInstance(NavigationManager::class); // Filament builds it once per process
        $html = $this->actingAs($user->fresh())->get(Dashboard::getUrl())->assertOk()->getContent();
        preg_match_all('/<span[^>]*class="[^"]*fi-sidebar-item-label[^"]*"[^>]*>\s*(.*?)\s*<\/span>/s', (string) $html, $m);

        return array_values(array_map(fn (string $l): string => html_entity_decode(trim(strip_tags($l))), $m[1]));
    }

    /** @return array<string, string> label => URL of the five sections */
    private function sectionUrls(): array
    {
        return [
            'Socios' => MemberResource::getUrl('index'),
            'Solicitudes' => MemberApplicationResource::getUrl('index'),
            'Cajas' => TillSessionResource::getUrl('index'),
            'Documentos generados' => MemberDocumentResource::getUrl('index'),
            'Seguridad' => Seguridad::getUrl(),
        ];
    }

    // --- 1–2. The staff panel ------------------------------------------------------------------------------------------

    public function test_staff_given_only_the_panel_see_none_of_the_five_sections(): void
    {
        $this->giveStaffThePanel();
        $staff = $this->user(Role::STAFF);

        $nav = $this->navigation($staff);
        foreach (array_keys($this->sectionUrls()) as $label) {
            $this->assertNotContains(__($label), $nav, "«{$label}» is in a staff panel that was only given panel.access");
        }
        $this->assertSame(array_map(fn (string $l): string => __($l), self::ALWAYS), $nav);

        foreach ($this->sectionUrls() as $label => $url) {
            $this->actingAs($staff)->get($url)->assertForbidden();
        }
    }

    public function test_switching_on_till_history_shows_cajas_and_switching_it_off_hides_it(): void
    {
        $this->giveStaffThePanel();
        $staff = $this->user(Role::STAFF);

        $this->setRolePermission(Role::STAFF, 'panel.tills', true);
        $this->assertContains(__('Cajas'), $this->navigation($staff));
        $this->actingAs($staff->fresh())->get(TillSessionResource::getUrl('index'))->assertOk();

        $this->setRolePermission(Role::STAFF, 'panel.tills', false);
        $this->assertNotContains(__('Cajas'), $this->navigation($staff));
        $this->actingAs($staff->fresh())->get(TillSessionResource::getUrl('index'))->assertForbidden();
    }

    // --- 3. Owner and manager: exactly as before --------------------------------------------------------------------------

    public function test_the_owners_panel_is_unchanged(): void
    {
        $this->assertSame(['Panel', 'Mostrador', 'Manual', 'Glosario', 'Socios', 'Solicitudes', 'Tarifas', 'Genéticas', 'Lotes', 'Inventario', 'Descuentos', 'Productos', 'Cajas', 'Dispensaciones', 'Gastos', 'Barra y tienda', 'Categorías de gasto', 'Compras', 'Proveedores', 'Financiero', 'Barra y tienda', 'Consumo', 'Descuentos y ajustes', 'Existencias', 'Asistencia', 'Horas del personal', 'Cajas', 'Socios', 'Deudores', 'Comité / Asamblea', 'Libro de socios', 'Actas', 'Convocatorias', 'Asamblea', 'Plantillas', 'Registro de dispensación', 'Exportación contable', 'Documentos generados', 'Avisos', 'Mensajes', 'Eventos', 'Mostradores registrados', 'Registro de jornada', 'Seguridad', 'Personal', 'Sedes', 'Identidad del club', 'Ajustes', 'Textos de consentimiento', 'Matriz de cumplimiento', 'Registro de auditoría', 'Solicitudes RGPD', 'RAT — Registro de tratamientos', 'Roles y permisos', 'Brechas de seguridad', 'Salud del sistema', 'Trabajos fallidos'],
            $this->navigation($this->user(Role::OWNER)));
    }

    public function test_the_managers_panel_is_unchanged(): void
    {
        $this->assertSame(['Panel', 'Mostrador', 'Manual', 'Glosario', 'Socios', 'Solicitudes', 'Genéticas', 'Lotes', 'Inventario', 'Descuentos', 'Productos', 'Cajas', 'Dispensaciones', 'Gastos', 'Barra y tienda', 'Compras', 'Proveedores', 'Financiero', 'Barra y tienda', 'Consumo', 'Descuentos y ajustes', 'Existencias', 'Asistencia', 'Horas del personal', 'Cajas', 'Socios', 'Deudores', 'Libro de socios', 'Actas', 'Convocatorias', 'Asamblea', 'Plantillas', 'Registro de dispensación', 'Exportación contable', 'Documentos generados', 'Avisos', 'Mensajes', 'Eventos', 'Mostradores registrados', 'Registro de jornada', 'Seguridad', 'Sedes'],
            $this->navigation($this->user(Role::MANAGER)));
    }

    // --- 5. The guard -------------------------------------------------------------------------------------------------

    /**
     * Every permission a counter screen checks — read from the counter's own code, so a check added tomorrow joins the
     * guard without anyone remembering to list it — plus the full STAFF default set.
     *
     * @return list<string>
     */
    private function counterPermissions(): array
    {
        $found = [];
        foreach (['app/Livewire/Counter', 'resources/views/livewire/counter', 'app/Http/Controllers'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                preg_match_all("/(?:can|canAny|userCan|operatorCan|deviceCan|hasPermissionTo)\\(\\s*\\[?((?:'[a-z_.]+'\\s*,?\\s*)+)/", $file->getContents(), $m);
                foreach ($m[1] as $list) {
                    preg_match_all("/'([a-z_.]+)'/", $list, $names);
                    array_push($found, ...$names[1]);
                }
            }
        }
        preg_match_all("/'([a-z_.]+)'/", File::get(app_path('Support/CounterScreens.php')), $screens);

        $all = array_merge($found, array_intersect($screens[1], Permissions::ALL), Permissions::defaultsFor(Role::STAFF));

        return array_values(array_unique(array_filter(
            array_intersect($all, Permissions::ALL),
            fn (string $p): bool => ! str_starts_with($p, 'panel.') && ! in_array($p, self::SAME_JOB, true),
        )));
    }

    public function test_the_panel_plus_every_counter_permission_opens_only_the_home_and_the_help(): void
    {
        $counter = $this->counterPermissions();
        $this->assertContains('till.close', $counter, 'the scan found no counter permissions — the guard would prove nothing');
        $this->assertContains('lockdown.initiate', $counter);

        $staff = $this->user(Role::STAFF);
        $staff->givePermissionTo([...$counter, 'panel.access']);

        $this->assertSame(array_map(fn (string $l): string => __($l), self::ALWAYS), $this->navigation($staff),
            'a counter permission opened a panel page by itself: pair its gate with a panel.* permission');

        $panel = Filament::getPanel('admin');
        $open = [];
        foreach ($panel->getResources() as $resource) {
            if ($resource::hasPage('index') && $this->actingAs($staff->fresh())->get($resource::getUrl('index'))->status() !== 403) {
                $open[] = $resource;
            }
        }
        foreach ($panel->getPages() as $page) {
            // The home, the help, and *Confirma tu identidad* — the panel's own identity check, not a section.
            if (in_array($page, [Dashboard::class, Manual::class, Glosario::class, ConfirmIdentity::class], true)) {
                continue;
            }
            if ($this->actingAs($staff->fresh())->get($page::getUrl())->status() !== 403) {
                $open[] = $page;
            }
        }
        $this->assertSame([], $open, 'these panel URLs answer a user who holds only counter permissions');
    }

    // --- 6. The roles page and the sync ---------------------------------------------------------------------------------

    public function test_the_sections_are_on_the_roles_page_under_the_admin_panel_needing_panel_access(): void
    {
        $this->assertSame(['panel.access', ...self::SECTIONS], Permissions::groups()[__('Panel de administración')] ?? null);
        foreach (self::SECTIONS as $permission) {
            $this->assertContains($permission, Permissions::ALL);
            $this->assertSame(['panel.access'], Permissions::DEPENDENCIES[$permission] ?? null);
            $this->assertNotSame($permission, Permissions::label($permission), "{$permission} has no words");
        }
        $this->assertSame(__('Ver historial de cajas'), Permissions::label('panel.tills'));

        $this->assertEqualsCanonicalizing(self::SECTIONS, array_values(array_intersect(self::SECTIONS, Permissions::defaultsFor(Role::MANAGER))));
        $this->assertSame([], array_values(array_intersect(self::SECTIONS, Permissions::defaultsFor(Role::STAFF))));

        $this->actingAs($this->user(Role::OWNER));
        $page = Livewire::test(RolesPermissions::class)->assertSee(__('Panel de administración'));
        foreach (self::SECTIONS as $permission) {
            $page->assertSee(Permissions::label($permission));
        }
    }

    public function test_a_sync_adds_the_sections_and_keeps_the_owners_choices(): void
    {
        $this->setRolePermission(Role::STAFF, 'panel.tills', true);
        $this->setRolePermission(Role::MANAGER, 'panel.security', false);

        $this->artisan('csc:sync-permissions')->assertSuccessful();

        $staff = SpatieRole::findByName(Role::STAFF->value, 'web');
        $manager = SpatieRole::findByName(Role::MANAGER->value, 'web');
        $this->assertTrue($staff->hasPermissionTo('panel.tills'));
        $this->assertFalse($staff->hasPermissionTo('panel.members'));
        $this->assertFalse($manager->hasPermissionTo('panel.security'));
        $this->assertTrue($manager->hasPermissionTo('panel.members'));
    }
}
