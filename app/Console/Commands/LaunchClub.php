<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Console\Command;

/**
 * Prompt 304 — the one-way launch latch. Run on the day the first real member is served: it stamps
 * `organisations.launched_at`, and from then on `csc:reset-for-launch` and `csc:install --force` refuse for good. There is no
 * command or flag that clears it. Running it again reports the date and changes nothing.
 */
class LaunchClub extends Command
{
    protected $signature = 'csc:launch';

    protected $description = 'Mark the club as live — after this the pre-launch reset can never run';

    public function handle(): int
    {
        $org = Organisation::query()->first();
        if ($org === null) {
            $this->error(__('No hay ningún club instalado. Usa `php artisan csc:install`.'));

            return self::FAILURE;
        }

        if ($org->launched_at !== null) {
            $this->info(__('El club ya está en marcha desde el :date.', ['date' => $org->launched_at->format('d/m/Y H:i')]));

            return self::SUCCESS;
        }

        if (! $this->confirm(__('¿Marcar :name como en marcha? Después no se podrá reiniciar.', ['name' => $org->name]))) {
            return self::FAILURE;
        }

        $org->forceFill(['launched_at' => now()])->save();
        app(ActiveScope::class)->setOrganisation($org->id);
        (new RecordAuditLog)->handle('system.launched', $org, null, ['launched_at' => $org->launched_at?->toIso8601String()]);

        $this->info(__('Club marcado como en marcha.').' '.__('`csc:reset-for-launch` ya no se puede usar.'));

        return self::SUCCESS;
    }
}
