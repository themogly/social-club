<?php

namespace Tests;

use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The settings memo is a request-lifetime static (prompt 109); reset it between tests so one test's
        // resolved value can never leak into the next (the same isolation a fresh request gets).
        Settings::flush();
    }
}
