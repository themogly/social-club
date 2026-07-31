<?php

namespace Tests\Feature\Discounts;

use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountMode;
use App\Enums\Role;
use App\Filament\Resources\Discounts\DiscountResource;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\DiscountsRelationManager;
use App\Models\AuditLog;
use App\Models\Discount;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDiscount;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 27 — the per-member custom discount UI (the reported bug: no way to attach a
 * discount to a member). The org-wide templates resource + ResolvePrice + the assign
 * action already existed; this adds the missing member-detail tab that feeds them.
 */
class MemberDiscountUiTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        // A base (no-tier) price so ResolvePrice has a rate to discount.
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    public function test_owner_assigns_a_custom_discount_through_the_ui_and_resolveprice_applies_it(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callAction('assign', ['mode' => DiscountMode::PERCENT->value, 'value_pct' => 15, 'reason' => 'Socio VIP'])
            ->assertHasNoActionErrors();

        $discount = MemberDiscount::query()->where('member_id', $this->member->id)->firstOrFail();
        $this->assertSame(DiscountMode::PERCENT, $discount->mode);
        $this->assertSame(1500, $discount->value_bp);          // 15% → 1500 bp

        // The resolver returns the discounted line for the member's next transaction.
        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(1000, $line['subtotal_cents']);      // €10,00/g × 1 g
        $this->assertSame(150, $line['discount_cents']);       // 15%
        $this->assertSame(850, $line['total_cents']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.discount.assigned']);
    }

    public function test_an_expired_custom_discount_is_not_applied_and_the_ui_shows_expired(): void
    {
        MemberDiscount::factory()->create([
            'member_id' => $this->member->id, 'discount_id' => null,
            'mode' => DiscountMode::PERCENT, 'value_bp' => 3000, 'value_cents' => null,
            'expires_at' => now()->subDay(),
        ]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(0, $line['discount_cents']);          // expired → not applied

        $this->actingAs($this->user(Role::OWNER));
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->assertSee(__('Caducado'));                        // reflected, not silently gone
    }

    public function test_member_discount_assign_is_denied_to_manager_and_staff(): void
    {
        // UI: the tab is hidden from non-owners.
        $manager = $this->user(Role::MANAGER);
        $this->actingAs($manager);
        $this->assertFalse(DiscountsRelationManager::canViewForRecord($this->member, ViewMember::class));

        $this->actingAs($this->user(Role::STAFF));
        $this->assertFalse(DiscountsRelationManager::canViewForRecord($this->member, ViewMember::class));

        $this->actingAs($this->user(Role::OWNER));
        $this->assertTrue(DiscountsRelationManager::canViewForRecord($this->member, ViewMember::class));

        // Backend: the underlying action refuses a manager even if the UI were bypassed.
        $this->expectException(AuthorizationException::class);
        (new AssignMemberDiscount)->handle($this->member, $manager, ['mode' => DiscountMode::PERCENT, 'value_bp' => 1000]);
    }

    public function test_a_discount_change_writes_an_audit_entry_with_who_and_from_to(): void
    {
        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);

        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callAction('assign', ['mode' => DiscountMode::PERCENT->value, 'value_pct' => 10, 'reason' => 'Inicial'])
            ->assertHasNoActionErrors();

        $discount = MemberDiscount::query()->where('member_id', $this->member->id)->firstOrFail();

        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callTableAction('edit', $discount, ['mode' => DiscountMode::PERCENT->value, 'value_pct' => 25, 'reason' => 'Subida'])
            ->assertHasNoActionErrors();

        $this->assertSame(2500, $discount->fresh()?->value_bp);

        $log = AuditLog::query()->where('action', 'member.discount.updated')->firstOrFail();
        $this->assertSame($owner->id, $log->actor_id);          // who
        $this->assertSame(1000, $log->before['value_bp']);      // from
        $this->assertSame(2500, $log->after['value_bp']);       // to
        $this->assertSame('Subida', $log->after['reason']);     // captured reason
    }

    public function test_the_pos_and_receipt_show_the_applied_custom_discount_label(): void
    {
        (new AssignMemberDiscount)->handle($this->member, $this->user(Role::OWNER), [
            'mode' => DiscountMode::PERCENT, 'value_bp' => 1500,
        ]);

        // ResolvePrice is the single source the POS and the receipt both render.
        $label = (string) (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->label();
        $this->assertStringContainsString(__('Personalizado'), $label);
        $this->assertStringContainsString('15', $label);        // −15.00%
    }

    public function test_the_org_wide_discount_templates_resource_is_registered_and_gated(): void
    {
        // Already built: the resource is gated by discounts.manage (owner sees it in nav, staff never).
        $this->actingAs($this->user(Role::OWNER));
        $this->assertTrue(DiscountResource::canViewAny());

        $this->actingAs($this->user(Role::STAFF));
        $this->assertFalse(DiscountResource::canViewAny());

        // A template, enabled for the location, resolves through the SAME resolver.
        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);
        $discount = Discount::factory()->create([
            'organisation_id' => $this->org->id, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 2000, 'value_cents' => null, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true,
        ]);
        $discount->locations()->attach($this->location->id);
        (new AssignMemberDiscount)->handle($this->member, $owner, ['discount_id' => $discount->id]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(200, $line['discount_cents']);        // 20% of €10,00
    }
}
