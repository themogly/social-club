<?php

namespace Tests\Feature\Security;

use App\Actions\Pricing\SetBatchPrice;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\TransferBatch;
use App\Enums\LocationKind;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Post-296 audit, Phase 1 findings 1 and 2.
 *
 * (1) A non-owner who can reach more than one location — since 277 that is any manager with their sede AND the
 * Almacén — started every session with NO active location, and `LocationScope` adds no filter then: they saw and
 * acted on every sede (another sede's dispensation list, its batches, its prices, its stock). Only the OWNER may be
 * in the "all locations" rollup (`LocationSwitcher::canAccess(null)`), so a non-owner now always has one of their
 * own locations active, checked on every panel request, and pricing and transferring a batch check the actor
 * works where the batch is.
 *
 * (2) Bulk delete / restore skipped the per-record policy: a manager bulk-deleted their own sede, and a manager holding
 * `staff.manage` bulk-deleted the OWNER. Every bulk delete / restore / force-delete now asks the policy per record.
 */
class NonOwnerScopeAndBulkActionsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $sedeA;

    private Location $sedeB;

    private Location $almacen;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sedeA = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede A']);
        $this->sedeB = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede B']);
        $this->almacen = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);

        $this->manager = User::factory()->create();
        $this->manager->assignRole(Role::MANAGER->value);
        $this->manager->locations()->sync([$this->sedeA->id, $this->almacen->id]);
    }

    private function batchAt(Location $sede, string $batchNo): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        return (new IntakeBatch)->handle($genetic, $sede, ['grams' => '100', 'batch_no' => $batchNo, 'price_per_gram_cents' => 1000]);
    }

    // --- (1) The scope ------------------------------------------------------------------------------------------------

    public function test_a_manager_with_a_sede_and_the_store_never_sees_another_sede(): void
    {
        $this->batchAt($this->sedeB, 'B-OTRASEDE');
        $this->batchAt($this->sedeA, 'B-MISEDE01');
        app(ActiveScope::class)->setLocation(null);

        $html = (string) $this->actingAs($this->manager)->get('/batches')->assertOk()->getContent();

        $this->assertStringContainsString('B-MISEDE01', $html);
        $this->assertStringNotContainsString('B-OTRASEDE', $html, 'a manager saw another sede\'s stock');
        $this->assertContains(session('scope.location_id'), [$this->sedeA->id, $this->almacen->id], 'the non-owner was left in the rollup');
    }

    public function test_a_stale_session_location_the_user_no_longer_works_at_is_replaced(): void
    {
        $this->batchAt($this->sedeB, 'B-OTRASEDE');

        $html = (string) $this->actingAs($this->manager)->withSession(['scope.location_id' => $this->sedeB->id])->get('/batches')->assertOk()->getContent();

        $this->assertStringNotContainsString('B-OTRASEDE', $html);
        $this->assertNotSame($this->sedeB->id, session('scope.location_id'));
    }

    public function test_the_owner_keeps_the_all_locations_rollup(): void
    {
        $this->batchAt($this->sedeB, 'B-OTRASEDE');
        $this->batchAt($this->sedeA, 'B-MISEDE01');

        $html = (string) $this->actingAs($this->owner)->withSession(['scope.location_id' => null])->get('/batches')->assertOk()->getContent();

        $this->assertStringContainsString('B-OTRASEDE', $html);
        $this->assertStringContainsString('B-MISEDE01', $html);
    }

    public function test_pricing_a_batch_at_a_sede_the_actor_does_not_work_at_is_refused(): void
    {
        $theirs = $this->batchAt($this->sedeB, 'B-OTRASEDE');

        try {
            (new SetBatchPrice)->handle($theirs, 1, null, $this->manager);
            $this->fail('a manager repriced another sede\'s batch');
        } catch (AuthorizationException) {
        }

        $this->assertSame(1000, $theirs->fresh()->price_per_gram_cents);
        $this->assertSame(500, (new SetBatchPrice)->handle($this->batchAt($this->sedeA, 'B-MISEDE01'), 500, null, $this->manager)->price_per_gram_cents);
    }

    public function test_transferring_stock_out_of_a_sede_the_actor_does_not_work_at_is_refused(): void
    {
        $theirs = $this->batchAt($this->sedeB, 'B-OTRASEDE');

        $this->expectException(AuthorizationException::class);
        (new TransferBatch)->handle($theirs, $this->sedeA, 100, $this->manager);
    }

    // --- (2) Bulk actions ask the policy per record -------------------------------------------------------------------

    public function test_a_manager_cannot_bulk_delete_their_own_sede(): void
    {
        app(ActiveScope::class)->setLocation($this->sedeA->id);

        Livewire::actingAs($this->manager)->test(ListLocations::class)->callTableBulkAction('delete', [$this->sedeA]);

        $this->assertFalse($this->sedeA->fresh()->trashed(), 'the bulk delete skipped LocationPolicy::delete');
    }

    public function test_a_manager_with_staff_manage_cannot_bulk_delete_the_owner(): void
    {
        $this->setRolePermission(Role::MANAGER, 'staff.manage', true);
        app(ActiveScope::class)->setLocation($this->sedeA->id);

        Livewire::actingAs($this->manager->fresh())->test(ListUsers::class)->callTableBulkAction('delete', [$this->owner]);

        $this->assertNotSoftDeleted($this->owner);
    }

    public function test_a_viewer_cannot_bulk_delete_a_member(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::STAFF->value);
        $viewer->locations()->sync([$this->sedeA->id]);
        $this->setRolePermission(Role::STAFF, 'panel.access', true);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        app(ActiveScope::class)->setLocation($this->sedeA->id);

        if (! $viewer->fresh()->can('members.view') || $viewer->fresh()->can('members.edit')) {
            $this->markTestSkipped('the default STAFF role no longer has view-without-edit on members');
        }

        Livewire::actingAs($viewer->fresh())->test(ListMembers::class)->callTableBulkAction('delete', [$member]);

        $this->assertNotSoftDeleted($member);
    }
}
