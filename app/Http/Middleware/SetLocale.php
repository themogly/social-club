<?php

namespace App\Http\Middleware;

use App\Actions\ResolveLocale;
use App\Models\User;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
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
        $user = $request->user();
        $locale = (new ResolveLocale)->handle($user instanceof User ? $user : null);

        $session = $request->session()->get('locale');
        $enabled = Settings::get('enabled_locales', ['en', 'es']);
        if (is_string($session) && is_array($enabled) && in_array($session, $enabled, true)) {
            $locale = $session;
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
