<?php

namespace App\Support;

/**
 * Prompt 327 — may the demo seeders (`DemoDataSeeder`, `DevAdminSeeder`) run here? On a developer's machine (`local`),
 * yes. On `staging`, ONLY when `csc:seed-staging` is running them (it sets the flag below after its refusals have
 * passed), so a plain `db:seed` there still skips them. On `production` (or anything else), never — whatever is set.
 */
final class StagingSeed
{
    public const FLAG = 'csc.seeding_staging';

    public static function allowed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return app()->environment('staging') && app()->bound(self::FLAG) && app(self::FLAG) === true;
    }
}
