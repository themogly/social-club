<?php

namespace App\Support;

use App\Enums\Role as RoleEnum;
use App\Models\RolePermissionOverride;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Does this database still match {@see Permissions}? (prompt 214)
 *
 * **The defect this exists for.** `RolePermissionSeeder` is idempotent by design and is exactly the right
 * thing to run after a permission changes — and it was called from **one place**: `csc:install`, which runs
 * once, at first deploy, and refuses a second run without `--force`. The deploy sequence in `SETUP.md` was
 * `git pull` → `composer install` → `npm ci` → `npm run build` → `migrate --force` → `storage:link` →
 * caches → Horizon. **Nothing re-synced roles.** So a club kept whatever permission matrix existed on the day
 * it was installed, for ever:
 *
 *   · a permission ADDED to a role never arrived — prompt 203's `membership.enrol` is the live example, and
 *     the symptom was an OWNER being told *"ask a manager"* for something the code grants them (and no
 *     manager who could either);
 *   · a permission REMOVED from a role was never revoked, which is **the worse direction**: `Permissions::for()`
 *     is the file everyone treats as the source of truth for who may do what, while the database quietly keeps
 *     a grant deleted from the code months ago. Prompts 122 and 174 both moved permissions between roles;
 *     either could have left a stale grant on a live club;
 *   · and it failed **silently** — no error, no log line, only an operator refused something the code says
 *     they may do, which reads as an application bug and gets reported as one.
 *
 * This class is the read half: it computes the difference without writing anything, so the same answer can be
 * reported by `csc:sync-permissions --check` in a deploy log AND by *Salud del sistema* to somebody who never
 * sees one. Closing the silence only in the deploy script would leave anyone who deployed some other way
 * still blind.
 */
class PermissionDrift
{
    /**
     * Every way this database disagrees with the code, as plain sentences.
     *
     * @return array{
     *   missing_permissions: list<string>,
     *   missing_grants: array<string, list<string>>,
     *   stale_grants: array<string, list<string>>,
     *   missing_roles: list<string>,
     *   in_sync: bool
     * }
     */
    public static function report(): array
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        $missingPermissions = array_values(array_diff(Permissions::ALL, $existing));
        $missingRoles = [];
        $missingGrants = [];
        $staleGrants = [];

        foreach (RoleEnum::cases() as $case) {
            $role = Role::query()->where('name', $case->value)->where('guard_name', 'web')->first();

            if ($role === null) {
                $missingRoles[] = $case->value;

                continue;
            }

            $held = $role->permissions()->pluck('name')->all();
            $should = Permissions::for($case);

            // Added in code, absent here — the "ask a manager" symptom.
            if ($missing = array_values(array_diff($should, $held))) {
                $missingGrants[$case->value] = $missing;
            }

            // Held here, gone from the code — the security half, and the one nobody would look for.
            if ($stale = array_values(array_diff($held, $should))) {
                $staleGrants[$case->value] = $stale;
            }
        }

        return [
            'missing_permissions' => $missingPermissions,
            'missing_grants' => $missingGrants,
            'stale_grants' => $staleGrants,
            'missing_roles' => $missingRoles,
            'in_sync' => $missingPermissions === [] && $missingGrants === [] && $staleGrants === [] && $missingRoles === [],
        ];
    }

    /**
     * The club's deliberate differences from the code's defaults (prompt 262) — information, NOT drift: the roles are
     * measured against defaults + these, so an owner's choice on Sistema ▸ Roles y permisos never shows red.
     *
     * @return list<string>
     */
    public static function overrideLines(): array
    {
        if (! Schema::hasTable('role_permission_overrides')) {
            return [];
        }

        return RolePermissionOverride::query()->orderBy('role')->orderBy('permission')->get()
            ->map(fn ($o): string => ($o->granted ? __(':role: añadido «:permission»', ['role' => $o->role, 'permission' => Permissions::label($o->permission)])
                : __(':role: retirado «:permission»', ['role' => $o->role, 'permission' => Permissions::label($o->permission)])))
            ->all();
    }

    /**
     * The drift as short lines, for a deploy log or a health panel.
     *
     * @return list<string>
     */
    public static function lines(): array
    {
        $report = self::report();
        $lines = [];

        foreach ($report['missing_roles'] as $role) {
            $lines[] = __('Falta el rol :role.', ['role' => $role]);
        }

        if ($report['missing_permissions'] !== []) {
            $lines[] = __('Faltan :count permisos en el catálogo: :list', [
                'count' => count($report['missing_permissions']),
                'list' => implode(', ', array_slice($report['missing_permissions'], 0, 5)),
            ]);
        }

        foreach ($report['missing_grants'] as $role => $permissions) {
            $lines[] = __(':role no tiene :list', ['role' => $role, 'list' => implode(', ', $permissions)]);
        }

        foreach ($report['stale_grants'] as $role => $permissions) {
            $lines[] = __(':role conserva permisos retirados del código: :list', ['role' => $role, 'list' => implode(', ', $permissions)]);
        }

        return $lines;
    }
}
