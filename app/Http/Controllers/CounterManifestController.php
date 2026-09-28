<?php

namespace App\Http\Controllers;

use App\Actions\ResolveLocale;
use App\Support\OrganisationIdentity;
use Illuminate\Http\JsonResponse;

/**
 * Prompt 290 — the counter's own installable app, "Mostrador" / "Counter", beside the member area's (`/socio`, a static
 * manifest). Dynamic so it carries the club's name and language (the ORGANISATION's locale, not the viewer's).
 *
 * `scope: "/"` on purpose: the counter sends people to `/login`, the lockdown page and the panel, and a narrower scope
 * would open each with Chrome's out-of-scope bar — exactly what the owner wants gone. The member app's `/socio` scope is
 * more specific, so an installed member app is unaffected. Behind the counter's own gate (a person or a registered
 * counter) and linked with `crossorigin="use-credentials"`, so the club's name is never a public page. No service worker:
 * the counter shows member data and is never served from a cache.
 */
class CounterManifestController
{
    public function show(): JsonResponse
    {
        $locale = (new ResolveLocale)->handle();
        $short = __('Mostrador', [], $locale);

        return response()->json([
            'id' => '/counter',
            'name' => OrganisationIdentity::tradingName().' · '.$short,
            'short_name' => $short,
            'start_url' => '/counter',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'lang' => $locale,
            'dir' => 'ltr',
            // The counter's own tokens: brand blue bar, the surface-alt page it opens on.
            'theme_color' => '#2563eb',
            'background_color' => '#f8fafc',
            'icons' => [
                ['src' => '/counter-icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/counter-icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/counter-icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
