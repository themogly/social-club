<?php

namespace App\Support\Alerts;

use App\Actions\ResolveLocale;
use App\Enums\AlertType;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Support\LocationSwitcher;
use Closure;
use Illuminate\Support\Collection;

/**
 * Prompt 311 — who hears about what. A person needs `alerts.receive` (OWNER and MANAGER by default), and hears about the
 * sedes they work at (all of them for the owner) — narrowed to the sedes and alert types they chose on *Avisos*. A System
 * alert has no sede: everyone with the permission who takes that type hears it.
 */
final class AlertRecipients
{
    /** @return Collection<int, array{user: User, locations: non-empty-list<string>}> the people of this organisation who receive alerts (each with at least one sede) */
    public static function for(Organisation $organisation): Collection
    {
        $switcher = app(LocationSwitcher::class);

        return User::query()->where('active', true)->get()
            ->filter(fn (User $user): bool => $user->canUseTheApp() && $user->can('alerts.receive'))
            ->map(function (User $user) use ($switcher, $organisation): array {
                $mine = $switcher->available($user, includeStores: true)->where('organisation_id', $organisation->id)->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
                $chosen = $user->alertLocationIds();

                return ['user' => $user, 'locations' => array_values($chosen === null ? $mine : array_intersect($mine, $chosen))];
            })
            ->filter(fn (array $recipient): bool => $recipient['locations'] !== [])
            ->values();
    }

    /**
     * The alerts among `$states` this person takes.
     *
     * @param  array{user: User, locations: list<string>}  $recipient
     * @param  Collection<int, OwnerAlertState>  $states
     * @return Collection<int, OwnerAlertState>
     */
    public static function relevant(array $recipient, Collection $states): Collection
    {
        $types = $recipient['user']->alertTypes();

        return $states->filter(fn (OwnerAlertState $state): bool => in_array($state->type->value, $types, true)
            && ($state->location_id === null ? $state->type === AlertType::SYSTEM : in_array($state->location_id, $recipient['locations'], true)))
            ->values();
    }

    /**
     * Run `$callback` in this person's own language.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inTheirLanguage(User $user, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale((new ResolveLocale)->handle($user));

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
