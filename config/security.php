<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | Shipped REPORT-ONLY by default so a too-tight policy surfaces violations in
    | the browser console without breaking the Filament panel / Livewire / Alpine
    | (Alpine needs 'unsafe-eval' + 'unsafe-inline'). Once the report stream is
    | clean, flip CSP_ENFORCE=true to switch the header to the enforcing variant.
    | Even permissive, this still blocks external/injected scripts, framing, and
    | base-uri / form-action hijacking.
    |
    */

    'csp_enforce' => env('CSP_ENFORCE', false),

    'csp' => [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "object-src 'none'",
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Sent ONLY in production over HTTPS — never on local/dev or plain HTTP, so a
    | developer machine can't get pinned to HTTPS. `preload` is deliberately NOT
    | included: it is an effectively irreversible public commitment (submitting to
    | the browsers' preload list), to be enabled by hand once the club is ready.
    |
    */

    'hsts_max_age' => env('HSTS_MAX_AGE', 31_536_000), // 1 year

];
