<?php

namespace Tests\Feature\Filament;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Support\MenuSection;
use App\Models\AuditLog;
use App\Models\DataRequest;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Prompt 344 — the member page showed 12 header buttons over three rows, five of them red or destructive (*Registrar
 * baja, Expulsar, Solicitar supresión, Borrar*, the amber *Suspender*) BETWEEN the everyday ones: on a tablet, *Expulsar*
 * was a slip of the finger from *Cuenta del socio*. Everyday first; destructive grouped last, in *Más acciones*.
 */
class HeaderActionOrderTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$sede->id]);
        $this->actingAs($owner);
        $this->member = Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE, 'email' => 'socio@example.es']);
    }

    private function editPage(): Testable
    {
        return Livewire::test(EditMember::class, ['record' => $this->member->id]);
    }

    /** @return array<string, Action|ActionGroup> visible top-level header items, by name ('more' for the group) */
    private function visibleHeader(Testable $page): array
    {
        $items = [];
        foreach ($page->instance()->getCachedHeaderActions() as $item) {
            if ($item->isVisible()) {
                $items[$item instanceof ActionGroup ? 'more' : $item->getName()] = $item;
            }
        }

        return $items;
    }

    /** @return array<int, array<int, string>> the visible action names in each section of *Más acciones*, headings left out */
    private function sections(ActionGroup $more): array
    {
        return array_values(array_filter(array_map(
            fn (ActionGroup $section): array => array_values(array_map(fn (Action $a): string => $a->getName(), array_filter(
                $section->getActions(),
                fn (Action $a): bool => $a->isVisible() && ! $a instanceof MenuSection,
            ))),
            $more->getActions(),
        )));
    }

    // --- 1. The member edit page ---------------------------------------------------------------------------------------------

    public function test_the_member_edit_header_shows_the_five_everyday_actions_in_order_then_mas_acciones(): void
    {
        $header = $this->visibleHeader($this->editPage());

        $this->assertSame(['backToList', 'setDebtLimit', 'resendQr', 'setLimits', 'generateDocument', 'more'], array_keys($header));

        [$datos, $estado, $eliminar] = $this->sections($header['more']);
        $this->assertSame(['updateDeclaredForecast', 'exportData', 'view'], $datos); // the temporary conversions and carencia are offered when they apply
        $this->assertSame(['suspend', 'recordBaja', 'expel'], $estado);
        $this->assertSame(['requestErasure', 'delete'], $eliminar, 'Solicitar supresión and Borrar, last');
    }

    public function test_the_view_page_leads_with_editar_and_keeps_the_same_grouping(): void
    {
        $header = $this->visibleHeader(Livewire::test(ViewMember::class, ['record' => $this->member->id]));

        $this->assertSame(['backToList', 'edit', 'setDebtLimit', 'resendQr', 'setLimits', 'generateDocument', 'more'], array_keys($header));
        $this->assertSame(['suspend', 'recordBaja', 'expel'], $this->sections($header['more'])[1]);
        $this->assertSame(['requestErasure'], $this->sections($header['more'])[2]);
    }

    public function test_the_sections_are_titled_and_the_last_is_red(): void
    {
        $html = $this->editPage()->html();

        foreach (['Datos', 'Estado del socio', 'Eliminar'] as $title) {
            $this->assertMatchesRegularExpression('/data-menu-section="[^"]+" class="fi-menu-section-heading[^"]*">'.preg_quote(e(__($title)), '/').'</', $html);
        }
        $this->assertMatchesRegularExpression('/fi-menu-section-heading-danger">'.preg_quote(e(__('Eliminar')), '/').'</', $html);
        $this->assertStringContainsString('data-more-actions', $html);
        $this->assertLessThan(strpos($html, 'fi-menu-section-heading-danger'), strpos($html, e(__('Expulsar'))), 'Eliminar comes after the status section');
    }

    public function test_a_section_the_role_cannot_use_disappears_whole(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([app(ActiveScope::class)->locationId()]);
        $this->actingAs($staff);

        $page = Livewire::test(ViewMember::class, ['record' => $this->member->id]);
        $html = $page->html();
        if (! str_contains($html, 'data-more-actions')) {
            $this->assertTrue(true, 'staff see no Más acciones at all');

            return;
        }
        // Whatever staff may do, no title hangs over an empty section.
        foreach ($this->visibleHeader($page)['more']->getActions() as $section) {
            $visible = array_filter($section->getActions(), fn (Action $a): bool => $a->isVisible());
            $headings = array_filter($visible, fn (Action $a): bool => $a instanceof MenuSection);
            $this->assertTrue($headings === [] || count($visible) > 1, 'a section title with nothing under it');
        }
    }

    // --- 2. Each moved action still confirms and audits (pins) ---------------------------------------------------------------

    public function test_suspender_with_a_reason_still_suspends_and_audits(): void
    {
        $this->editPage()->callAction('suspend', ['reason' => 'Impago'])->assertHasNoActionErrors();

        $this->assertSame(MemberStatus::SUSPENDED, $this->member->fresh()->status);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'member.status.suspended')->count());
    }

    public function test_suspender_and_expulsar_still_require_their_reason(): void
    {
        $this->editPage()->callAction('suspend', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        $this->editPage()->callAction('expel', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame(MemberStatus::ACTIVE, $this->member->fresh()->status);
    }

    public function test_expulsar_with_a_reason_still_expels_and_audits(): void
    {
        $this->editPage()->callAction('expel', ['reason' => 'Reventa'])->assertHasNoActionErrors();

        $this->assertSame(MemberStatus::EXPELLED, $this->member->fresh()->status);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'member.status.expelled')->count());
    }

    public function test_registrar_baja_still_confirms_requires_its_reason_and_audits(): void
    {
        $page = $this->editPage()->mountAction('recordBaja');
        $this->assertTrue($page->instance()->getMountedAction()?->isConfirmationRequired());
        $page->callMountedAction(['reason' => ''])->assertHasActionErrors(['reason' => 'required']);

        $this->editPage()->callAction('recordBaja', ['reason' => 'Se muda'])->assertHasNoActionErrors();
        $this->assertNotNull($this->member->fresh()->left_at);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'member.membership.cancelled')->count());
    }

    public function test_solicitar_supresion_still_confirms_and_registers_the_request(): void
    {
        $page = $this->editPage()->mountAction('requestErasure');
        $this->assertTrue($page->instance()->getMountedAction()?->isConfirmationRequired());
        $page->callMountedAction();

        $this->assertSame(1, DataRequest::query()->withoutGlobalScopes()->where('member_id', $this->member->id)->count());
    }

    public function test_borrar_still_confirms_and_soft_deletes(): void
    {
        $page = $this->editPage()->mountAction('delete');
        $this->assertTrue($page->instance()->getMountedAction()?->isConfirmationRequired());
        $page->callMountedAction();

        $this->assertSoftDeleted($this->member);
    }

    // --- 4. Structural: on every resource edit/view page, nothing destructive before something harmless --------------------

    /**
     * Read statically from each page's `getHeaderActions()`, as declared: a top-level item is DESTRUCTIVE when it is a
     * Delete/ForceDelete or `->color('danger')`. Restore belongs to the destructive tail (it replaces Delete on a
     * trashed record — the two are never shown together). A ⋯ group is harmless at the top level; its own order is the
     * sections'.
     */
    public function test_no_resource_page_shows_a_destructive_action_before_a_harmless_one(): void
    {
        $checked = 0;
        foreach (File::allFiles(app_path('Filament/Resources')) as $file) {
            if (! preg_match('#/Pages/(Edit|View)\w+\.php$#', $file->getPathname())) {
                continue;
            }
            $class = 'App\\'.Str::of($file->getPathname())->after(app_path().'/')->replace(['/', '.php'], ['\\', ''])->toString();
            if (! is_subclass_of($class, EditRecord::class) && ! is_subclass_of($class, ViewRecord::class)) {
                continue;
            }

            $method = new ReflectionMethod($class, 'getHeaderActions');
            $items = $method->invoke(app($class));
            $seenDestructive = null;
            foreach ($items as $item) {
                $kind = $this->kind($item);
                if ($kind === 'destructive') {
                    $seenDestructive ??= $item->getName();
                } elseif ($kind === 'harmless' && $seenDestructive !== null) {
                    $this->fail(class_basename($class).": «{$seenDestructive}» (destructive) comes before «".($item instanceof Action ? $item->getName() : 'a group').'»');
                }
            }
            $checked++;
        }

        $this->assertGreaterThan(20, $checked);
    }

    private function kind(Action|ActionGroup $item): string
    {
        if ($item instanceof ActionGroup) {
            return 'harmless';
        }
        if ($item instanceof RestoreAction) {
            return 'tail';
        }
        if ($item instanceof DeleteAction || $item instanceof ForceDeleteAction) {
            return 'destructive';
        }
        $color = (new ReflectionProperty(Action::class, 'color'))->getValue($item);

        return $color === 'danger' ? 'destructive' : 'harmless';
    }
}
