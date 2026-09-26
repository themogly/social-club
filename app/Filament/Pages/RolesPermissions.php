<?php

namespace App\Filament\Pages;

use App\Actions\Roles\RestoreRoleDefaults;
use App\Actions\Roles\SetRolePermission;
use App\Enums\Role;
use App\Models\User;
use App\Support\Permissions;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Sistema ▸ Roles y permisos (prompt 262) — the owner decides what STAFF and MANAGER may do.
 *
 * OWNER ROLE only, deliberately not a permission: a permission can be granted, and a manager handed "may edit roles"
 * could hand themselves everything. The owner column is shown ticked and locked — the owner always holds everything,
 * and `SetRolePermission` refuses any attempt to change it. Each tick is a stored override of the code's default
 * (it survives every deploy and is never reported as drift), is live at once, and is audited from → to.
 */
class RolesPermissions extends Page
{
    protected string $view = 'filament.pages.roles-permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'roles-y-permisos';

    public static function getNavigationLabel(): string
    {
        return __('Roles y permisos');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Roles y permisos');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(Role::OWNER->value) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** Tick or untick one permission for STAFF or MANAGER. The owner column has no control; a crafted call is refused. */
    public function toggle(string $role, string $permission): void
    {
        abort_unless(static::canAccess(), 403);

        $roleEnum = Role::tryFrom($role);
        abort_if($roleEnum === null || $roleEnum === Role::OWNER, 403);

        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        $granted = ! in_array($permission, Permissions::for($roleEnum), true);
        (new SetRolePermission)->handle($roleEnum, $permission, $granted, $actor);

        if ($granted && in_array($permission, Permissions::SENSITIVE, true)) {
            Notification::make()
                ->title(__('Permiso sensible concedido'))
                ->body(__('«:permission» llega al núcleo de cumplimiento y privacidad. Queda registrado.', ['permission' => Permissions::label($permission)]))
                ->warning()->send();
        }
    }

    /**
     * "Conceder también" — grant the permission a held one needs (prompt 265), in one click from the warning. The same
     * audited writer as a tick; only ever a GRANT of a listed dependency.
     */
    public function grantDependency(string $role, string $permission): void
    {
        abort_unless(static::canAccess(), 403);

        $roleEnum = Role::tryFrom($role);
        abort_if($roleEnum === null || $roleEnum === Role::OWNER, 403);
        abort_unless(in_array($permission, array_merge(...array_values(Permissions::DEPENDENCIES)), true), 403);

        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        (new SetRolePermission)->handle($roleEnum, $permission, true, $actor);
    }

    public function restoreDefaults(string $role): void
    {
        abort_unless(static::canAccess(), 403);

        $roleEnum = Role::tryFrom($role);
        abort_if($roleEnum === null || $roleEnum === Role::OWNER, 403);

        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        (new RestoreRoleDefaults)->handle($roleEnum, $actor);

        Notification::make()->title(__('Valores por defecto restaurados'))->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $editable = [Role::MANAGER, Role::STAFF];

        return [
            'groups' => Permissions::groups(),
            'roles' => $editable,
            'held' => collect($editable)->mapWithKeys(fn (Role $r): array => [$r->value => Permissions::for($r)])->all(),
            'defaults' => collect($editable)->mapWithKeys(fn (Role $r): array => [$r->value => Permissions::defaultsFor($r)])->all(),
            'sensitive' => Permissions::SENSITIVE,
            // Prompt 265 — held grants that need another the role lacks, per role.
            'dependencies' => collect($editable)->mapWithKeys(fn (Role $r): array => [$r->value => Permissions::missingDependencies(Permissions::for($r))])->all(),
        ];
    }
}
