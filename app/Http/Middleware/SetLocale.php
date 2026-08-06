<?php

namespace App\Http\Middleware;

use App\Actions\ResolveLocale;
use App\Support\Settings;
use Closure;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the request locale through the single {@see ResolveLocale} resolver
 * (per-user preference → organisation default → system default `en`). The language
 * switcher persists the choice to the user row AND drops an in-session override so
 * the change shows on the very next request without a re-login; that override wins
 * here only while it names an enabled locale. Reads config through Settings — never
 * throws on a stale value, it degrades to the next level.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The subject is whoever is authenticated on the ACTIVE guard — a User in the admin panel, or a
        // Member on the socio routes (guard `member`, whose provider is NOT Users). The old code only
        // looked at the default guard, so a member's preference was skipped entirely (prompt 96).
        $subject = $request->user();
        if (! $subject instanceof HasLocalePreference) {
            $member = Auth::guard('member')->user();
            $subject = $member instanceof HasLocalePreference ? $member : null;
        }
        $locale = (new ResolveLocale)->handle($subject);

        $session = $request->session()->get('locale');
        $enabled = Settings::get('enabled_locales', ['en', 'es']);
        if (is_string($session) && is_array($enabled) && in_array($session, $enabled, true)) {
            $locale = $session;
        }

        // NOT consulted: the browser's Accept-Language. Prompt 167 weighed it and decided against —
        // prompt 96 deliberately made the club default the only lever for a visitor with no
        // preference, the switcher (now reachable on every screen) already lets an applicant change
        // language in one tap, and honouring the hint would silently override a club's configured
        // default on every anonymous page. See DECISIONS for the measurements.

        app()->setLocale($locale);

        return $next($request);
    }
}
