<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security + no-index headers on every response.
 *
 * `X-Robots-Tag: noindex` is a legal constraint, not a preference: a Spanish CSC
 * may not advertise, so nothing here may be indexed (see NOTES §A). On top of that
 * baseline this adds a Content-Security-Policy (report-only until deliberately
 * enforced) and, in production over HTTPS only, HSTS — both driven by config/security.php.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->applyContentSecurityPolicy($response);
        $this->applyStrictTransportSecurity($request, $response);

        return $response;
    }

    /**
     * CSP is report-only by default (config('security.csp_enforce') flips it to enforcing),
     * so a too-tight policy is observed before it can break the Filament panel / Livewire.
     */
    private function applyContentSecurityPolicy(Response $response): void
    {
        $policy = implode('; ', (array) config('security.csp', []));

        if ($policy === '') {
            return;
        }

        $header = config('security.csp_enforce')
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $policy);
    }

    /**
     * HSTS in production over HTTPS only — never on local/dev or plain HTTP, so a developer
     * machine can't get pinned to HTTPS. No `preload` (an irreversible public commitment).
     */
    private function applyStrictTransportSecurity(Request $request, Response $response): void
    {
        if (! app()->environment('production') || ! $request->isSecure()) {
            return;
        }

        $maxAge = (int) config('security.hsts_max_age', 31_536_000);
        $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
    }
}
