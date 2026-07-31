<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_noindex_and_baseline_headers_are_present_on_every_response(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        // Legal constraint: nothing here may be indexed (NOTES §A).
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_csp_ships_report_only_until_deliberately_enforced(): void
    {
        $response = $this->get('/login');

        // Default: observe violations without breaking the panel — report-only, not enforcing.
        $response->assertHeader('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy-Report-Only'),
        );
    }

    public function test_csp_switches_to_enforcing_when_configured(): void
    {
        config(['security.csp_enforce' => true]);

        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_hsts_is_absent_outside_production_even_over_https(): void
    {
        // A developer machine must never get pinned to HTTPS.
        $this->get('https://localhost/login')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_in_production_over_https_without_preload(): void
    {
        $this->app['env'] = 'production';

        $header = (string) $this->get('https://localhost/login')
            ->headers->get('Strict-Transport-Security');

        $this->assertStringContainsString('max-age=', $header);
        $this->assertStringContainsString('includeSubDomains', $header);
        // preload is an irreversible public commitment — never enabled implicitly.
        $this->assertStringNotContainsString('preload', $header);
    }
}
