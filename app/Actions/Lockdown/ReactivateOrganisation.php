<?php

namespace App\Actions\Lockdown;

use App\Actions\RecordAuditLog;
use App\Models\OrganisationLockdown;
use App\Models\User;

/**
 * Close an open lockdown (prompt 121) — the one writer for every "way back". The method records HOW the club got
 * back in, which is itself evidence:
 *   - owner_link  — an owner clicked their emailed link, off the terminal (owner trust);
 *   - auto_delay  — the safety-net timer elapsed (no human — so a locked-out data controller always regains
 *                   access to their own statutory register);
 *   - break_glass — a platform operator ran the CLI (server access, highest trust);
 *   - drill_ended — an owner ended a rehearsal in-app.
 *
 * It never re-opens a closed lockdown and is idempotent.
 */
class ReactivateOrganisation
{
    public function handle(OrganisationLockdown $lockdown, string $method, ?User $by = null): OrganisationLockdown
    {
        if (! $lockdown->isOpen()) {
            return $lockdown;
        }

        $lockdown->update([
            'reactivated_at' => now(),
            'reactivated_by' => $by?->id,
            'reactivation_method' => $method,
        ]);

        (new RecordAuditLog)->handle('org.lockdown.reactivated', $lockdown->organisation, null, [
            'method' => $method,
            'is_drill' => $lockdown->is_drill,
        ]);

        return $lockdown->refresh();
    }
}
