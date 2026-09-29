<?php

namespace Tests;

use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
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

    /**
     * A sede for this person to work at, so a panel request reaches the PAGE's own gate. Prompt 310 found a dozen "is
     * forbidden to staff" tests whose user had no sede at all: `EnsureActiveLocation` answered 403 before the page's
     * permission was ever asked, and each test passed whatever that permission said.
     */
    protected function giveASede(User $user): User
    {
        $organisationId = app(ActiveScope::class)->organisationId() ?? Organisation::factory()->create()->id;
        $user->locations()->syncWithoutDetaching([Location::factory()->create(['organisation_id' => $organisationId])->id]);

        return $user->fresh() ?? $user;
    }
}
