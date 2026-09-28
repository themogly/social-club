<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Prompt 286 — how many people still hold a LEGACY (bcrypt) PIN. Each of them makes the counter's PIN check slower until
 * they enter their PIN once, which upgrades them to the keyed lookup. Read-only.
 */
class PinUpgradeStatus extends Command
{
    protected $signature = 'csc:pin-upgrade-status';

    protected $description = 'Report how many active users are still on the legacy (bcrypt) PIN hash';

    public function handle(): int
    {
        $active = User::query()->where('active', true);

        $legacy = (clone $active)->whereNull('pin_lookup')->whereNotNull('pin')->count();
        $upgraded = (clone $active)->whereNotNull('pin_lookup')->count();
        $none = (clone $active)->whereNull('pin_lookup')->whereNull('pin')->count();

        $this->table(['Estado', 'Personas activas'], [
            ['PIN actualizado (búsqueda rápida)', $upgraded],
            ['PIN antiguo (bcrypt, lento hasta que lo usen)', $legacy],
            ['Sin PIN', $none],
        ]);

        $this->line($legacy === 0
            ? 'Nadie queda en el hash antiguo: la comprobación lenta ya no se ejecuta.'
            : "{$legacy} persona(s) siguen con el hash antiguo; se actualizan al introducir su PIN una vez.");

        return self::SUCCESS;
    }
}
