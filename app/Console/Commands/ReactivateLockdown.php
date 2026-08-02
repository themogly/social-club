<?php

namespace App\Console\Commands;

use App\Actions\Lockdown\ReactivateOrganisation;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use Illuminate\Console\Command;

/**
 * Prompt 121 — the break-glass "way back", at the highest trust level: running it needs shell access to the
 * server, which a raider in the room does not have. A platform operator uses it when a club cannot reach their
 * own inbox and will not wait for the auto-delay. Reactivation is recorded as `break_glass`.
 */
class ReactivateLockdown extends Command
{
    protected $signature = 'lockdown:reactivate {organisation? : Organisation id (defaults to the only org)}';

    protected $description = 'Break-glass: lift the panic lockdown for an organisation from the CLI';

    public function handle(ReactivateOrganisation $reactivate): int
    {
        $organisationId = $this->argument('organisation')
            ?? Organisation::query()->withoutGlobalScopes()->orderBy('created_at')->value('id');

        if ($organisationId === null) {
            $this->error('No organisation found.');

            return self::FAILURE;
        }

        $lockdown = OrganisationLockdown::active((string) $organisationId);
        if ($lockdown === null) {
            $this->info('That organisation is not locked down.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Lift the lockdown for organisation {$organisationId}?", true)) {
            return self::SUCCESS;
        }

        $reactivate->handle($lockdown, 'break_glass');
        $this->info('Lockdown lifted.');

        return self::SUCCESS;
    }
}
