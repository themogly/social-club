<?php

namespace App\Support;

use App\Providers\AppServiceProvider;
use Filament\Notifications\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Prompt 363, part 2 — Sentry on production (5 Oct 2026): `Collection::fromLivewire(): Argument #1 ($notification) must be
 * of type array, int given`. Filament's notifications Collection trusts that every item it gets back from Livewire is a
 * notification array; an update request our page never builds — any anonymous client can take the Notifications
 * component's snapshot from the public /login page — can replace `notifications` with junk, and the panel 500s.
 * Upstream: filamentphp/filament#19447 (the same TypeError beside locked-property updates no browser sends), closed as
 * not planned; Livewire 4.4.7 does not touch it.
 *
 * Bound in the container ({@see AppServiceProvider::register()}) because `fromLivewire()` resolves through
 * `app(static::class, ['items' => …])` and the class's constructor is `final` (no subclass). Non-array items are DROPPED
 * and logged with their types (never their values); valid notifications are untouched. REMOVE when Filament validates
 * the shape itself.
 */
final class NotificationsUpdateGuard
{
    /** @param  array{items?: mixed}  $parameters */
    public static function make(array $parameters): Collection
    {
        $items = is_array($parameters['items'] ?? null) ? $parameters['items'] : [];
        $valid = array_filter($items, 'is_array');

        if (count($valid) !== count($items)) {
            Log::warning('Dropped non-notification items from a Livewire update to Filament notifications', [
                'component' => 'Filament\\Livewire\\Notifications',
                'dropped' => array_values(array_map('get_debug_type', array_diff_key($items, $valid))),
            ]);
        }

        return new Collection($valid);
    }
}
