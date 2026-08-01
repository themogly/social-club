<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Filament\Resources\Dispensations\DispensationResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 122 — an operational permission must not authorise BROWSING everyone else's archive. Operating the
 * till (`pos.use`/`pos.bar`) and taking petty cash (`expenses.record`) no longer open the whole dispensation
 * (Article-9), order or expense archive; browsing is a reporting/treasury concern. The counter's legitimate
 * SINGLE-member reads still work.
 */
class ViewAnyScopeTest extends TestCase
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

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    public function test_staff_cannot_browse_the_dispensation_expense_or_order_archive(): void
    {
        $this->user(Role::STAFF);

        // The assertions that name this branch: the archive routes are refused to a counter operator.
        $this->assertFalse(DispensationResource::canViewAny());
        $this->assertFalse(ExpenseResource::canViewAny());
        $this->assertFalse(OrderResource::canViewAny());
    }

    public function test_a_manager_can_browse_all_three_archives(): void
    {
        $this->user(Role::MANAGER);

        $this->assertTrue(DispensationResource::canViewAny());
        $this->assertTrue(ExpenseResource::canViewAny());
        $this->assertTrue(OrderResource::canViewAny());
    }

    public function test_staff_can_still_read_a_single_dispensation_for_the_member_at_the_counter(): void
    {
        $staff = $this->user(Role::STAFF);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
        ]);

        // The single-member read (receipt reprint / refund lookup) stays open on `view` — only the browsable
        // archive (viewAny) closed.
        $this->assertTrue($staff->can('view', $dispensation));
        $this->assertFalse($staff->can('viewAny', Dispensation::class));
    }

    public function test_staff_can_no_longer_enrol_a_member_and_a_manager_still_can(): void
    {
        $staff = $this->user(Role::STAFF);
        $this->assertFalse($staff->can('create', Member::class));
        $this->assertFalse($staff->can('applications.review'));   // the reviewed route was already manager-gated

        $manager = $this->user(Role::MANAGER);
        $this->assertTrue($manager->can('create', Member::class)); // enrolment stays possible — at manager level
        $this->assertTrue($manager->can('applications.review'));   // both admission routes now sit together
    }

    public function test_staff_keep_every_permission_the_counter_shift_needs(): void
    {
        $staff = $this->user(Role::STAFF);

        // Regression guard at the permission level: the full shift (open till, check a member in, dispense, bar
        // order, collect a fee, record petty cash) is untouched by this branch.
        foreach (['till.open', 'checkin.manage', 'pos.use', 'pos.bar', 'membership.fee.collect', 'expenses.record', 'members.view'] as $permission) {
            $this->assertTrue($staff->can($permission), "staff must keep {$permission}");
        }
    }
}
