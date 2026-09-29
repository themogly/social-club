<?php

namespace App\Support;

use App\Enums\LocationKind;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Prompt 322 — after a save that set a PIN: say whose it is and where to try it, or that it won't work anywhere.
 * "Where it works" is `UnlockOperator`'s own rule: the person is active and assigned to the sede (only sedes have a
 * counter; a closed sede has none).
 */
final class PinSavedNotice
{
    /** @return list<string> the names of the sedes where this person's PIN opens the counter */
    public static function sedes(User $user): array
    {
        if (! $user->active) {
            return [];
        }

        return $user->locations()->withoutGlobalScopes()
            ->where('locations.active', true)->where('locations.kind', LocationKind::SEDE->value)
            ->orderBy('locations.name')->pluck('locations.name')->map(fn (mixed $name): string => (string) $name)->values()->all();
    }

    public static function send(User $user): void
    {
        $sedes = self::sedes($user);

        match (true) {
            ! $user->active => Notification::make()->warning()->title(__('El PIN no funcionará: esta persona está desactivada.'))->send(),
            $sedes === [] => Notification::make()->warning()->title(__('El PIN no funcionará: esta persona no tiene ninguna sede asignada.'))->send(),
            default => Notification::make()->success()
                ->title(__('PIN guardado para :name. Pruébalo en el mostrador de :sedes.', ['name' => $user->name, 'sedes' => implode(', ', $sedes)]))->send(),
        };
    }
}
