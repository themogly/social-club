<?php

namespace App\Actions\Counter;

use App\Actions\RecordAuditLog;
use App\Models\CounterTerminal;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Revoke a registered counter (prompt 289) — "Olvidar este dispositivo" at the counter, or Revocar in the panel (a stolen
 * or lost tablet). Its next request lands on the normal login. `terminals.manage`, at a sede the person may reach.
 * Audited `counter.terminal.revoked`.
 */
class RevokeCounterTerminal
{
    /** @throws AuthorizationException */
    public function handle(CounterTerminal $terminal, User $by): void
    {
        if (! $by->can('terminals.manage')
            || ! app(LocationSwitcher::class)->available($by)->contains(fn (Location $l): bool => $l->id === $terminal->location_id)) {
            throw new AuthorizationException(__('No puedes revocar este mostrador.'));
        }

        if ($terminal->revoked_at !== null) {
            return;
        }

        $terminal->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id])->save();

        (new RecordAuditLog)->handle('counter.terminal.revoked', $terminal, null, [
            'terminal_id' => $terminal->id,
            'location_id' => $terminal->location_id,
            'by' => $by->id,
        ]);
    }
}
