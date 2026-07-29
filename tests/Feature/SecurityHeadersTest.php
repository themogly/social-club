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
}
