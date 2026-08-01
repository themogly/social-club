<?php

namespace App\Actions;

use App\Support\Settings;
use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * THE single place the UI language is decided (same shape as ResolveMemberLimits /
 * ResolvePrice — one named home, never duplicated or hardcoded). Resolution order:
 *
 *   explicit per-subject preference → organisation default → system default (en)
 *
 * The subject is anything with a language preference — a `User` (admin panel) or a `Member` (PWA), both
 * via the `HasLocalePreference` contract (prompt 96), so there is ONE resolver for both, not a member
 * fork. Only an ENABLED locale is honoured; anything else falls through to the next level, so a stale or
 * unknown value degrades gracefully rather than throwing. Read through Settings::get (safe fallback),
 * never a raw property access — so a queued job (no HTTP, no session) resolves safely too.
 */
class ResolveLocale
{
    public function handle(?HasLocalePreference $subject = null): string
    {
        $enabled = $this->enabled();
        $system = 'en';

        foreach ([$subject?->preferredLocale(), Settings::get('default_locale', $system), config('app.locale')] as $candidate) {
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
