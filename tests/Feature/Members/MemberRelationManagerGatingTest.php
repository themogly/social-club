<?php

namespace Tests\Feature\Members;

use App\Enums\Role;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\RelationManagers\ConsumptionRelationManager;
use App\Filament\Resources\Members\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Members\RelationManagers\VisitsRelationManager;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 81 — the Consumption / Visits / Orders relation managers lift LocationScope to show a socio's
 * history across EVERY sede, which is Article-9 special-category data. They must be gated on reports.view
 * (oversight) — a STAFF member with only members.view (counter intake) must never see them.
 */
class MemberRelationManagerGatingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string<RelationManager>> */
    private const MANAGERS = [
        ConsumptionRelationManager::class,
        VisitsRelationManager::class,
        OrdersRelationManager::class,
    ];

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

    public function test_staff_cannot_view_the_article9_relation_managers(): void
    {
        $this->userWithRole(Role::STAFF); // members.view, but NOT reports.view
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        foreach (self::MANAGERS as $manager) {
            $this->assertFalse(
                $manager::canViewForRecord($member, EditMember::class),
                "{$manager} exposes org-wide Article-9 data and must be hidden from STAFF."
            );
        }
    }

    public function test_a_manager_can_view_them(): void
    {
        $this->userWithRole(Role::MANAGER); // holds reports.view
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        foreach (self::MANAGERS as $manager) {
            $this->assertTrue($manager::canViewForRecord($member, EditMember::class));
        }
    }
}
