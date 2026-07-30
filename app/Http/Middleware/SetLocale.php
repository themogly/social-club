<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the session locale (set by the locale switcher) when it is one of the
 * organisation's enabled locales; otherwise leaves the configured default (es).
 * Reads through Settings::get with a safe fallback — never throws.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');
        $enabled = Settings::get('enabled_locales', ['es', 'en']);

        if (is_string($locale) && is_array($enabled) && in_array($locale, $enabled, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
