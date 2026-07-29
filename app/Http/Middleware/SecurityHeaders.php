<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security + no-index headers on every response.
 *
 * `X-Robots-Tag: noindex` is a legal constraint, not a preference: a Spanish CSC
 * may not advertise, so nothing here may be indexed (see NOTES §A). Prompt 17
 * layers stricter policy (CSP, HSTS) on top of this baseline.
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

        return $response;
    }
}
