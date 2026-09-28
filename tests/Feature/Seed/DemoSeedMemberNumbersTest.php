<?php

namespace Tests\Feature\Seed;

use App\Models\Member;
use App\Models\Organisation;
use App\Support\MemberNumber;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DevAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Post-296 completeness D1 — the demo seeder wrote `M-00001…` by hand and never advanced the organisation's member-number
 * counter, so the first real alta on a demo database collided on the unique `(organisation_id, member_no)`. It is the
 * "fixtures bypass the writer" drift CLAUDE.md forbids: the seeder now moves the counter past every number it places.
 */
class DemoSeedMemberNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_next_member_number_after_the_demo_seed_is_free(): void
    {
        $this->app['env'] = 'local'; // the demo seeders run only locally
        $this->seed([RolePermissionSeeder::class, DevAdminSeeder::class, DemoDataSeeder::class]);
        $org = Organisation::query()->firstOrFail();

        $next = MemberNumber::next($org->id);

        $this->assertFalse(Member::query()->withoutGlobalScopes()->where('organisation_id', $org->id)->where('member_no', $next)->exists(), "{$next} is already a seeded member's number");
    }
}
