<?php

namespace App\Console\Commands;

use App\Support\PermissionDrift;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * Make the permission matrix converge on the code, on every deploy (prompt 214).
 *
 * `RolePermissionSeeder` was called from exactly one place — `csc:install`, which runs once — so a club kept
 * its install-day matrix for ever and no later change, added or revoked, ever reached it. The symptom was an
 * OWNER being told *"ask a manager"* for something the code grants them. See {@see PermissionDrift} for the
 * full account.
 *
 * **Why a command and not the other two options.**
 *
 *   · A **migration** runs once. The matrix keeps changing, so a migration would fix the release it shipped
 *     in and leave the next one with the same gap — the shape of the problem, not the fix.
 *   · Putting **`db:seed --class=…`** in the release path works, and is one dropped flag away from running
 *     `DatabaseSeeder` — which in this repo reaches `DemoDataSeeder` — against production. A production
 *     deploy script should not contain a command whose failure mode is "seeds demo data into a live club".
 *   · A **dedicated command** says what it does, can be run on its own when something looks wrong, and can
 *     carry `--check` so the same code that fixes the drift is the code that reports it.
 *
 * It delegates to the seeder rather than reimplementing it: `RolePermissionSeeder`'s idempotency is the
 * property being relied on, and a second copy of the sync would be the exact class of bug this branch closes.
 *
 * **`syncPermissions()` REVOKES what is no longer listed**, and that is deliberately not softened into an
 * additive merge. A grant deleted from `Permissions::for()` must leave the database, or the file everyone
 * reads as the source of truth is not one. Per-USER grants (`model_has_permissions`) are untouched — this
 * syncs roles.
 */
class SyncPermissions extends Command
{
    protected $signature = 'csc:sync-permissions {--check : Report drift and exit non-zero; write nothing}';

    protected $description = 'Converge the roles and permission catalogue on App\Support\Permissions (idempotent)';

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->reportOnly();
        }

        $before = PermissionDrift::report();

        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        // The seeder busts it too; doing it here as well is what makes THIS command's contract complete —
        // `PERMISSION_CACHE_STORE=database` (prompt 124), and a sync that leaves a stale cache behind produces
        // the same silent symptom for the next cache lifetime.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($before['in_sync']) {
            $this->info('Permissions already matched the code — nothing changed.');

            return self::SUCCESS;
        }

        // What was wrong, from the BEFORE reading — after the sync there is by definition nothing to list,
        // and a deploy log that says "corrected:" followed by nothing tells the reader nothing.
        $this->warn('Permission drift corrected:');

        foreach ($before['missing_roles'] as $role) {
            $this->line('  · created role: '.$role);
        }
        if ($before['missing_permissions'] !== []) {
            $this->line('  · added to the catalogue: '.implode(', ', $before['missing_permissions']));
        }
        foreach ($before['missing_grants'] as $role => $permissions) {
            $this->line('  · granted to '.$role.': '.implode(', ', $permissions));
        }
        foreach ($before['stale_grants'] as $role => $permissions) {
            $this->line('  · REVOKED from '.$role.': '.implode(', ', $permissions));
        }

        if (! PermissionDrift::report()['in_sync']) {
            $this->error('The matrix still does not match the code after syncing — investigate before going live.');

            return self::FAILURE;
        }

        $this->info('Permissions now match the code.');

        return self::SUCCESS;
    }

    /** `--check`: for a launch gate or a CI step. Writes nothing, exits non-zero on drift. */
    private function reportOnly(): int
    {
        $report = PermissionDrift::report();

        if ($report['in_sync']) {
            $this->info('Permissions match App\Support\Permissions.');

            return self::SUCCESS;
        }

        $this->error('Permission drift — this club is running a matrix the code does not describe:');
        foreach (PermissionDrift::lines() as $line) {
            $this->line('  · '.$line);
        }
        $this->line('');
        $this->line('Run `php artisan csc:sync-permissions` to converge.');

        return self::FAILURE;
    }
}
