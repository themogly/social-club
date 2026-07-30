<?php

namespace Tests\Feature\Memberships;

use App\Actions\Members\WaiveCarencia;
use App\Enums\Role;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\MemberEligibility;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarenciaTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->member = Member::factory()->create([
            'organisation_id' => $org->id,
            'carencia_ends_at' => now()->addDays(15),
        ]);
    }

    public function test_carencia_blocks_now_but_passes_after_it_ends(): void
    {
        $this->assertFalse(MemberEligibility::carenciaPassed($this->member));
        $this->assertTrue(MemberEligibility::carenciaPassed($this->member, now()->addDays(16)));
    }

    public function test_a_manager_may_waive_carencia_and_it_is_logged(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);

        (new WaiveCarencia)->handle($this->member, $manager, 'Prior consumer, vouched.');

        $this->assertTrue(MemberEligibility::carenciaPassed($this->member->fresh()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.carencia.waived']);
    }

    public function test_staff_cannot_waive_carencia(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        $this->expectException(AuthorizationException::class);
        (new WaiveCarencia)->handle($this->member, $staff);
    }
}
