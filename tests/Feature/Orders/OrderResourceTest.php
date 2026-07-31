<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 43 — the read-only OrderResource + the Member orders relation manager. Denial
 * tests per CLAUDE.md: the list is org+location scoped, a voided sale shows its void audit,
 * and a member's tab shows only that member's purchases.
 */
class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->a->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->a->id);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(Location $location, OrderStatus $status = OrderStatus::COMPLETED, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'location_id' => $location->id,
            'status' => $status,
            'total_cents' => 500,
            'cash_cents' => 500,
            'wallet_cents' => 0,
            'items' => [['article_id' => null, 'name' => 'Cerveza', 'qty' => 1, 'unit_price_cents' => 500, 'line_total_cents' => 500]],
            'idempotency_key' => (string) Str::ulid(),
        ], $overrides));
    }

    public function test_the_list_is_scoped_to_the_active_location_and_hides_another_sede(): void
    {
        $this->owner(); // scoped to location A
        $here = $this->order($this->a);
        $elsewhere = $this->order($this->b);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$here])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    public function test_a_user_without_bar_or_reports_permission_cannot_view_the_resource(): void
    {
        $stranger = User::factory()->create(); // no role → no pos.bar / reports.view
        $this->assertFalse(Gate::forUser($stranger)->allows('viewAny', Order::class));

        $owner = $this->owner();
        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', Order::class));
    }

    public function test_a_voided_order_shows_its_void_reason_by_and_at(): void
    {
        $this->owner();
        $manager = User::factory()->create(['name' => 'Marta Responsable']);
        $order = $this->order($this->a, OrderStatus::VOIDED, [
            'void_reason' => 'El socio se equivocó de bebida',
            'voided_by' => $manager->id,
            'voided_at' => now(),
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertOk()
            ->assertSee('El socio se equivocó de bebida')
            ->assertSee('Marta Responsable');
    }

    public function test_the_member_relation_manager_shows_only_that_members_orders(): void
    {
        $this->owner();
        $m1 = Member::factory()->create(['organisation_id' => $this->org->id]);
        $m2 = Member::factory()->create(['organisation_id' => $this->org->id]);
        $mine = $this->order($this->a, overrides: ['member_id' => $m1->id]);
        $theirs = $this->order($this->a, overrides: ['member_id' => $m2->id]);

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $m1, 'pageClass' => ViewMember::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }
}
