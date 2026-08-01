<?php

namespace Tests\Feature\Discounts;

use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Pricing\ResolveArticleDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
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
use ReflectionMethod;
use Tests\TestCase;

/**
 * Prompt 119 — member discounts are CHOSEN from the org's pre-made templates, never hand-typed. Choosing the
 * template (not retyping its number) is what makes the discount's own scope (`applies_to`) hold: a genetics-only
 * rate does not leak onto the bar. Legacy inline rows created before this change still price and stay editable
 * for their expiry only. (Extends the prompt 08/27 UI.)
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
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'is_therapeutic' => false]);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function template(DiscountAppliesTo $appliesTo, int $valueBp = 1500, DiscountKind $kind = DiscountKind::STAFF): Discount
    {
        $discount = Discount::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => $kind, 'mode' => DiscountMode::PERCENT,
            'value_bp' => $valueBp, 'value_cents' => null, 'applies_to' => $appliesTo, 'active' => true,
        ]);
        $discount->locations()->attach($this->location->id);

        return $discount;
    }

    /** The discount ids the assign picker offers, via the relation manager's own option builder. */
    private function offeredDiscountIds(): array
    {
        $method = new ReflectionMethod(DiscountsRelationManager::class, 'discountOptions');
        $method->setAccessible(true);

        return array_keys($method->invoke(new DiscountsRelationManager));
    }

    // --- The picker: choose a template, not a number ---------------------------------

    public function test_assigning_from_the_picker_stores_a_linked_template_and_no_inline_value(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        $template = $this->template(DiscountAppliesTo::BOTH, 2000);

        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callAction('assign', ['discount_id' => $template->id, 'reason' => 'Socio VIP'])
            ->assertHasNoActionErrors();

        $md = MemberDiscount::query()->where('member_id', $this->member->id)->firstOrFail();
        // The dead path is now live: a LINK is stored, and not one of the old hand-typed inline values.
        $this->assertSame($template->id, $md->discount_id);
        $this->assertNull($md->mode);
        $this->assertNull($md->value_bp);
        $this->assertNull($md->value_cents);

        // And it prices through the same resolver (20% of €10,00/g).
        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(200, $line['discount_cents']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.discount.assigned']);
    }

    public function test_a_genetics_scoped_template_applies_to_genetics_but_not_the_bar(): void
    {
        // The correctness win. A genetics-only therapeutic rate must NOT touch the bar — which a hand-typed
        // "15%" could never guarantee, being global by construction.
        $owner = $this->user(Role::OWNER);
        $template = $this->template(DiscountAppliesTo::GENETIC, 1500, DiscountKind::THERAPEUTIC);
        (new AssignMemberDiscount)->handle($this->member, $owner, ['discount_id' => $template->id]);
        $member = $this->member->fresh();

        // Genetics: applied (15% of €10,00).
        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $member)->lineFor(100);
        $this->assertSame(150, $line['discount_cents']);

        // Bar: the SAME assigned discount contributes nothing, because its applies_to is GENETIC — even though
        // it is active and enabled at this location (so the exclusion is the scope, not a missing link).
        $this->assertSame(0, (new ResolveArticleDiscount)->bpFor($member, $this->location));
    }

    public function test_an_inactive_discount_is_not_offered(): void
    {
        $active = $this->template(DiscountAppliesTo::BOTH);
        $inactive = Discount::factory()->create([
            'organisation_id' => $this->org->id, 'mode' => DiscountMode::PERCENT, 'value_bp' => 1000,
            'value_cents' => null, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => false,
        ]);

        $offered = $this->offeredDiscountIds();
        $this->assertContains($active->id, $offered);
        $this->assertNotContains($inactive->id, $offered);
    }

    public function test_a_discount_from_another_organisation_is_not_offered(): void
    {
        $mine = $this->template(DiscountAppliesTo::BOTH);
        $otherOrg = Organisation::factory()->create();
        $theirs = Discount::factory()->create([
            'organisation_id' => $otherOrg->id, 'mode' => DiscountMode::PERCENT, 'value_bp' => 1000,
            'value_cents' => null, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true,
        ]);

        $offered = $this->offeredDiscountIds();
        $this->assertContains($mine->id, $offered);
        $this->assertNotContains($theirs->id, $offered);
    }

    // --- Editing: expiry only --------------------------------------------------------

    public function test_editing_changes_only_the_expiry_and_is_audited_from_to(): void
    {
        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);
        $template = $this->template(DiscountAppliesTo::BOTH, 2000);
        (new AssignMemberDiscount)->handle($this->member, $owner, ['discount_id' => $template->id]);
        $md = MemberDiscount::query()->where('member_id', $this->member->id)->firstOrFail();

        $newExpiry = now()->addMonth()->startOfDay();
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callTableAction('edit', $md, ['expires_at' => $newExpiry->toDateString(), 'reason' => 'Prórroga'])
            ->assertHasNoActionErrors();

        $md->refresh();
        $this->assertSame($template->id, $md->discount_id);                 // link preserved
        $this->assertSame($newExpiry->toDateString(), $md->expires_at?->toDateString());

        $log = AuditLog::query()->where('action', 'member.discount.updated')->firstOrFail();
        $this->assertSame($owner->id, $log->actor_id);
        $this->assertNull($log->before['expires_at']);                      // from
        $this->assertSame($newExpiry->toDateString(), $log->after['expires_at']); // to
        $this->assertSame('Prórroga', $log->after['reason']);               // captured reason
    }

    // --- Legacy inline rows: still price, editable for expiry only --------------------

    public function test_a_legacy_inline_row_still_prices_and_edits_only_its_expiry(): void
    {
        // A row created the old way (an inline value, no template link) must keep working.
        $inline = MemberDiscount::factory()->create([
            'member_id' => $this->member->id, 'discount_id' => null,
            'mode' => DiscountMode::PERCENT, 'value_bp' => 1200, 'value_cents' => null, 'expires_at' => null,
        ]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(120, $line['discount_cents']);                    // 12% still honoured by the resolver

        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callTableAction('edit', $inline, ['expires_at' => now()->addWeek()->toDateString(), 'reason' => 'Alargar'])
            ->assertHasNoActionErrors();

        $inline->refresh();
        $this->assertSame(1200, $inline->value_bp);                         // value frozen — only the expiry moved
        $this->assertNotNull($inline->expires_at);
    }

    public function test_an_expired_discount_is_not_applied_and_the_ui_shows_expired(): void
    {
        MemberDiscount::factory()->create([
            'member_id' => $this->member->id, 'discount_id' => null,
            'mode' => DiscountMode::PERCENT, 'value_bp' => 3000, 'value_cents' => null,
            'expires_at' => now()->subDay(),
        ]);

        $line = (new ResolvePrice)->forGenetic($this->genetic, $this->location, $this->member->fresh())->lineFor(100);
        $this->assertSame(0, $line['discount_cents']);                      // expired → not applied

        $this->actingAs($this->user(Role::OWNER));
        Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->assertSee(__('Caducado'));                                    // reflected, not silently gone
    }

    // --- Authorisation + audit -------------------------------------------------------

    public function test_member_discount_assign_is_denied_to_manager_and_staff(): void
    {
        $manager = $this->user(Role::MANAGER);
        $this->actingAs($manager);
        $this->assertFalse(DiscountsRelationManager::canViewForRecord($this->member, ViewMember::class));

        $this->actingAs($this->user(Role::STAFF));
        $this->assertFalse(DiscountsRelationManager::canViewForRecord($this->member, ViewMember::class));

        $this->actingAs($this->user(Role::OWNER));
        $this->assertTrue(DiscountsRelationManager::canViewForRecord($this->member, ViewMember::class));

        // Backend: the writer refuses a manager even if the UI were bypassed.
        $template = $this->template(DiscountAppliesTo::BOTH);
        $this->expectException(AuthorizationException::class);
        (new AssignMemberDiscount)->handle($this->member, $manager, ['discount_id' => $template->id]);
    }

    public function test_assign_edit_and_remove_each_write_an_audit_entry_with_the_reason(): void
    {
        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);
        $template = $this->template(DiscountAppliesTo::BOTH);
        $rm = fn () => Livewire::test(DiscountsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);

        $rm()->callAction('assign', ['discount_id' => $template->id, 'reason' => 'Alta'])->assertHasNoActionErrors();
        $md = MemberDiscount::query()->where('member_id', $this->member->id)->firstOrFail();

        $rm()->callTableAction('edit', $md, ['expires_at' => now()->addMonth()->toDateString(), 'reason' => 'Cambio'])->assertHasNoActionErrors();
        $rm()->callTableAction('remove', $md, ['reason' => 'Baja'])->assertHasNoActionErrors();

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.discount.assigned']);
        $this->assertSame('Cambio', AuditLog::query()->where('action', 'member.discount.updated')->firstOrFail()->after['reason']);
        $this->assertSame('Baja', AuditLog::query()->where('action', 'member.discount.removed')->firstOrFail()->before['reason']);
    }

    public function test_the_org_wide_discount_templates_resource_is_registered_and_gated(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        $this->assertTrue(DiscountResource::canViewAny());

        $this->actingAs($this->user(Role::STAFF));
        $this->assertFalse(DiscountResource::canViewAny());
    }
}
