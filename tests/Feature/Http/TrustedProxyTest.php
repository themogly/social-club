<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Prompt 78 — behind a reverse proxy the app must honour X-Forwarded-Proto, or isSecure() is false on an
 * HTTPS page and the panel/POS emit http:// asset URLs that the browser blocks as mixed content. This is
 * the functional proof (a forwarded https request is treated as secure) plus a source guard so removing the
 * config can't silently regress the go-live fix.
 */
class TrustedProxyTest extends TestCase
{
    public function test_a_forwarded_https_request_is_treated_as_secure(): void
    {
        // Arrives at the app over HTTP from the proxy, with the real scheme in the forwarded header.
        $this->get('/up', ['X-Forwarded-Proto' => 'https']);

        $this->assertTrue(
            request()->isSecure(),
            'X-Forwarded-Proto must be trusted so isSecure() is true behind a proxy (no mixed-content assets).'
        );
    }

    public function test_the_proxy_trust_is_configured(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('trustProxies', $bootstrap, 'bootstrap/app.php must configure trusted proxies.');
    }
}
