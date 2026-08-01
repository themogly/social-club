<?php

namespace Tests\Feature\Members;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Members\SetMemberLimits;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 81 — wiring the previously-dead `member.limits.set`. The per-member override is the TOP of the
 * limit precedence (member → tier → location → org), stored as integer centigrams, and only OWNER holds
 * the permission. Weight paths assert centigrams (CLAUDE.md): 5,00 g → 500 cg.
 */
class SetMemberLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_override_is_stored_in_centigrams_and_beats_the_org_default(): void
    {
        $owner = $this->userWithRole(Role::OWNER);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        // 5,00 g daily / 200,00 g monthly override (well above the org default daily of 3,5 g).
        (new SetMemberLimits)->handle($member, $owner, 500, 20000, 'Necesidad médica documentada');

        $member->refresh();
        $this->assertSame(500, $member->daily_limit_cg);
        $this->assertSame(20000, $member->monthly_limit_cg);

        // Precedence: the per-member override wins over tier/location/org in the resolver.
        $snapshot = (new ResolveMemberLimits)->handle($member, $this->location);
        $this->assertSame(500, $snapshot->dailyLimitCg);
        $this->assertSame(20000, $snapshot->monthlyLimitCg);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.limits.set']);
    }

    public function test_null_clears_the_override_and_falls_back_to_the_org_default(): void
    {
        $owner = $this->userWithRole(Role::OWNER);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        (new SetMemberLimits)->handle($member, $owner, 500, 20000, 'set');
        (new SetMemberLimits)->handle($member, $owner, null, null, 'clear');

        $member->refresh();
        $this->assertNull($member->daily_limit_cg);
        $this->assertNull($member->monthly_limit_cg);

        // With no override and no tier, the resolver falls back to the org default (a Setting).
        $snapshot = (new ResolveMemberLimits)->handle($member, $this->location);
        $this->assertSame((int) Settings::get('daily_limit_cg'), $snapshot->dailyLimitCg);
    }

    public function test_a_manager_without_the_permission_is_denied(): void
    {
        // member.limits.set is OWNER-only — even a MANAGER (who edits members) cannot set the override.
        $manager = $this->userWithRole(Role::MANAGER);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        $this->expectException(AuthorizationException::class);
        (new SetMemberLimits)->handle($member, $manager, 500, null, 'not allowed');
    }

    public function test_staff_is_denied(): void
    {
        $staff = $this->userWithRole(Role::STAFF);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        $this->expectException(AuthorizationException::class);
        (new SetMemberLimits)->handle($member, $staff, 500, null, 'not allowed');

        $this->assertNull($member->fresh()->daily_limit_cg);
    }
}
