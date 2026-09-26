<?php

namespace Tests\Concerns;

use App\Actions\Members\SetMemberDebtLimit;
use App\Enums\Role;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Since prompt 259 a member may owe only up to an owner-approved tab. A test that needs a member in debt approves
 * the tab the real way — through `SetMemberDebtLimit`, as the owner — never by writing the column by hand
 * (CLAUDE.md: fixtures go through the domain action that owns the write).
 */
trait ApprovesMemberTabs
{
    protected function approveTab(Member $member, int $limitCents = 1_000_000): Member
    {
        if (! SpatieRole::query()->where('name', Role::OWNER->value)->exists()) {
            $this->seed(RolePermissionSeeder::class);
        }

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        return (new SetMemberDebtLimit)->handle($member, $owner, $limitCents, 'Fixture: approved tab');
    }
}
