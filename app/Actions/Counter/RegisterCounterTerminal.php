<?php

namespace App\Actions\Counter;

use App\Actions\RecordAuditLog;
use App\Models\CounterTerminal;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register THIS tablet as a counter at a sede (prompt 289). `terminals.manage`, at a sede the person may reach (a manager
 * only their own); never the store (no counter there). Returns the terminal and its one-time plain token — the caller
 * puts it in the cookie; only its SHA-256 is stored. Audited `counter.terminal.registered`.
 */
class RegisterCounterTerminal
{
    /**
     * @return array{terminal: CounterTerminal, token: string}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $by, Location $location, string $name): array
    {
        if ($location->isStore()) {
            throw new InvalidArgumentException(__('El almacén no tiene mostrador.'));
        }

        if (! $by->can('terminals.manage')
            || ! app(LocationSwitcher::class)->available($by)->contains(fn (Location $l): bool => $l->id === $location->id)) {
            throw new AuthorizationException(__('No puedes registrar un mostrador en esta sede.'));
        }

        $name = Str::limit(trim($name), 40, '');
        if ($name === '') {
            throw new InvalidArgumentException(__('Ponle un nombre al dispositivo.'));
        }

        $token = Str::random(64);
        $terminal = CounterTerminal::query()->create([
            'organisation_id' => $location->organisation_id,
            'location_id' => $location->id,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'registered_by' => $by->id,
            'registered_at' => now(),
        ]);

        (new RecordAuditLog)->handle('counter.terminal.registered', $terminal, null, [
            'terminal_id' => $terminal->id,
            'location_id' => $location->id,
            'by' => $by->id,
        ]);

        return ['terminal' => $terminal, 'token' => $token];
    }
}
