<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Settings;

/**
 * THE single place the UI language is decided (same shape as ResolveMemberLimits /
 * ResolvePrice — one named home, never duplicated or hardcoded). Resolution order:
 *
 *   explicit per-user preference → organisation default → system default (en)
 *
 * Only an ENABLED locale is honoured; anything else falls through to the next level,
 * so a stale or unknown value degrades gracefully to English rather than throwing.
 * Read through Settings::get (safe fallback), never a raw property access.
 */
class ResolveLocale
{
    public function handle(?User $user = null): string
    {
        $enabled = $this->enabled();
        $system = 'en';

        foreach ([$user?->locale, Settings::get('default_locale', $system), config('app.locale')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $enabled, true)) {
                return $candidate;
            }
        }

        return in_array($system, $enabled, true) ? $system : ($enabled[0] ?? $system);
    }

    /** @return list<string> */
    private function enabled(): array
    {
        $enabled = Settings::get('enabled_locales', ['en', 'es']);

        return is_array($enabled) && $enabled !== [] ? array_values(array_filter($enabled, 'is_string')) : ['en', 'es'];
    }
}
