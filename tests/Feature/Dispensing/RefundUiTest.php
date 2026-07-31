<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Dispensations\DispensationResource;
use App\Filament\Resources\Dispensations\Pages\ViewDispensation;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Refund;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 71 — the surface that finally makes RefundDispensation reachable. It COLLECTS input and calls the
 * finished engine; it re-implements nothing. These prove the surface produces the same result as the action,
 * shows the remaining-refundable figure, offers merma/cash only when allowed, surfaces every refusal, shows
 * the refund on the member, and cannot be pointed at a dispensation the operator may not see.
 */
class RefundUiTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true, // €10/g
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay()]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    private function manager(?Location $at = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // dispensation.void + stock.merma
        $user->locations()->sync([($at ?? $this->location)->id]);

        return $user;
    }

    private function till(): TillSession
    {
        return (new OpenTill)->handle($this->location, 'TILL-01', 20000);
    }

    /** €10.00 / 100 cg, cash. */
    private function dispensation(?Member $member = null): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member ?? $this->member(), $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 100]],
            [],
        );
    }

    public function test_a_refund_through_the_surface_matches_the_action(): void
    {
        $this->actingAs($this->manager());
        $d = $this->dispensation();
        $till = $this->till();

        Livewire::test(ViewDispensation::class, ['record' => $d->getKey()])
            ->callAction('refund', [
                'amount_eur' => '4.00', 'weight_g' => '0.40',
                'destination' => RefundDestination::STOCK->value, 'method' => RefundMethod::CASH->value,
                'reason' => 'Producto mohoso',
            ])
            ->assertHasNoActionErrors();

        $refund = Refund::query()->withoutGlobalScopes()->where('dispensation_id', $d->id)->firstOrFail();
        $this->assertSame(400, $refund->amount_cents->cents);
        $this->assertSame(40, $refund->grams_cg->centigrams);
        $this->assertSame(RefundDestination::STOCK, $refund->destination);
        $this->assertSame(RefundMethod::CASH, $refund->method);
        $this->assertSame($till->id, $refund->till_session_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispensation.refunded']);
    }

    public function test_the_surface_shows_the_correct_remaining_after_a_partial_refund(): void
    {
        $manager = $this->manager();
        $d = $this->dispensation();

        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 400, 'grams_cg' => 0,
            'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET, 'reason' => 'x',
        ]);

        $this->assertSame(600, $d->refresh()->remainingRefundableCents());

        $this->actingAs($manager);
        Livewire::test(ViewDispensation::class, ['record' => $d->getKey()])
            ->assertSee(Money::fromCents(600)->formatted()); // €6,00 available, on the record
    }

    public function test_an_operator_without_stock_merma_is_not_offered_the_merma_destination(): void
    {
        $limited = User::factory()->create();
        $limited->givePermissionTo(['dispensation.void', 'pos.use', 'reports.view']); // NOT stock.merma
        $this->actingAs($limited);
        $this->assertSame([RefundDestination::STOCK->value], array_keys(DispensationResource::destinationOptions()));

        $this->actingAs($this->manager()); // MANAGER holds stock.merma
        $this->assertArrayHasKey(RefundDestination::MERMA->value, DispensationResource::destinationOptions());
    }

    public function test_cash_is_not_offered_without_an_open_till(): void
    {
        $d = $this->dispensation();
        $this->assertSame([RefundMethod::WALLET->value], array_keys(DispensationResource::methodOptions($d)));

        $this->till();
        $this->assertArrayHasKey(RefundMethod::CASH->value, DispensationResource::methodOptions($d));
    }

    public function test_an_over_refund_surfaces_a_stated_reason(): void
    {
        $manager = $this->manager();
        $d = $this->dispensation();
        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 600, 'grams_cg' => 0,
            'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET, 'reason' => 'Parte 1',
        ]);

        $this->actingAs($manager);
        Livewire::test(ViewDispensation::class, ['record' => $d->getKey()])
            ->callAction('refund', [
                'amount_eur' => '5.00', 'weight_g' => '0', // 600 + 500 > 1000
                'method' => RefundMethod::WALLET->value, 'reason' => 'Parte 2',
            ])
            ->assertNotified(__('No se pudo reembolsar'));

        $this->assertSame(600, $d->refresh()->refundedAmountCents()); // unchanged
    }

    public function test_a_refund_outside_the_window_surfaces_a_stated_reason(): void
    {
        Settings::set('refund_window_days', '30', SettingType::INT);
        $this->actingAs($this->manager());
        $d = $this->dispensation();
        $d->forceFill(['dispensed_at' => now()->subDays(40)])->save();

        Livewire::test(ViewDispensation::class, ['record' => $d->getKey()])
            ->callAction('refund', [
                'amount_eur' => '1.00', 'weight_g' => '0',
                'method' => RefundMethod::WALLET->value, 'reason' => 'Fuera de plazo',
            ])
            ->assertNotified(__('No se pudo reembolsar'));

        $this->assertSame(0, $d->refresh()->refundedAmountCents());
    }

    public function test_the_refund_action_is_hidden_without_permission(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // no dispensation.void
        $staff->locations()->sync([$this->location->id]);
        $d = $this->dispensation();

        $this->actingAs($staff);
        Livewire::test(ViewDispensation::class, ['record' => $d->getKey()])
            ->assertActionHidden('refund');
    }

    public function test_the_refund_appears_on_the_member_record(): void
    {
        $manager = $this->manager();
        $member = $this->member();
        $d = $this->dispensation($member);

        (new RefundDispensation)->handle($d, $manager, [
            'amount_cents' => 400, 'grams_cg' => 0,
            'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET, 'reason' => 'x',
        ]);

        // The member's refunds relation (what the RefundsRelationManager tab renders) carries it.
        $this->assertSame(1, $member->refunds()->withoutGlobalScopes()->count());
    }

    public function test_a_manager_cannot_refund_another_locations_dispensation(): void
    {
        // Object-ownership, not just authentication (CLAUDE.md §Security): a manager assigned only to another
        // sede must not reach — nor refund — this row, even with a tampered {record} id.
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        $elsewhere = $this->manager($other); // assigned to $other only
        $d = $this->dispensation();

        $this->assertFalse($elsewhere->can('view', $d));
        $this->assertFalse($elsewhere->can('refund', $d));

        $this->actingAs($elsewhere);
        Livewire::test(ViewDispensation::class, ['record' => $d->getKey()])
            ->assertForbidden();
    }
}
