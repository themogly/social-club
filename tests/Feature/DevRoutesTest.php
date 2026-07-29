<?php

namespace Tests\Feature;

use Tests\TestCase;

class DevRoutesTest extends TestCase
{
    /**
     * The test suite runs in the `testing` environment, not `local`, so the
     * developer routes must 404 — proving they are gated and would not exist in
     * production either.
     */
    public function test_dev_mail_preview_is_gated_to_local(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $this->get('/dev/mail')->assertNotFound();
        $this->get('/dev/mail/example-club-mail')->assertNotFound();
    }
}
