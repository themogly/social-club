<?php

namespace Tests\Feature\Members;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\AvaladosRelationManager;
use App\Filament\Resources\Members\RelationManagers\ConsumptionRelationManager;
use App\Filament\Resources\Members\RelationManagers\SanctionsRelationManager;
use App\Filament\Resources\Members\RelationManagers\VisitsRelationManager;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Scopes\LocationScope;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 51 — member resource completeness: the four missing relation tabs (consumption, visits,
 * sanctions, avalados), the carencia-waive action wiring up the caller-less WaiveCarencia, the wallet
 * balance + limits gauge on the Resumen, and the N+1-safe wallet balance list column.
 */
class MemberResourceCompletenessTest extends TestCase
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

    private function actor(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function member(): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->addDays(10), // in carencia
        ]);
    }

    public function test_the_four_new_relation_tabs_are_registered(): void
    {
        $relations = MemberResource::getRelations();

        foreach ([ConsumptionRelationManager::class, VisitsRelationManager::class, SanctionsRelationManager::class, AvaladosRelationManager::class] as $rm) {
            $this->assertContains($rm, $relations);
        }
    }

    public function test_waive_carencia_ends_the_period_and_audits(): void
    {
        $member = $this->member();
        $this->actingAs($this->actor(Role::OWNER));

        Livewire::test(ViewMember::class, ['record' => $member->getKey()])
            ->callAction('waiveCarencia', ['reason' => 'Aval verificado'])
            ->assertHasNoActionErrors();

        $this->assertTrue($member->fresh()->carencia_ends_at->lessThanOrEqualTo(now()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.carencia.waived', 'auditable_id' => $member->id]);
    }

    public function test_waive_carencia_is_hidden_without_the_permission(): void
    {
        $member = $this->member();
        $this->actingAs($this->actor(Role::STAFF)); // no carencia.waive

        Livewire::test(ViewMember::class, ['record' => $member->getKey()])
            ->assertActionHidden('waiveCarencia');
    }

    public function test_the_resumen_shows_wallet_balance_and_limits(): void
    {
        $member = $this->member();
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
        $this->actingAs($this->actor(Role::OWNER));

        Livewire::test(ViewMember::class, ['record' => $member->getKey()])
            ->assertOk()
            ->assertSee(__('Saldo del monedero'))
            ->assertSee(__('Límites de consumo'));
    }

    public function test_the_wallet_balance_column_aggregates_across_locations(): void
    {
        $member = $this->member();
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        (new RecordWalletTransaction)->handle($member, $this->location, 5000, WalletTransactionType::TOPUP);
        (new RecordWalletTransaction)->handle($member, $other, 3000, WalletTransactionType::TOPUP);

        // The same aggregate the list column uses — one subquery, across all locations, no N+1.
        $row = Member::query()->whereKey($member->id)->withSum(
            ['walletTransactions' => fn ($q) => $q->withoutGlobalScope(LocationScope::class)],
            'amount_cents',
        )->firstOrFail();

        $this->assertSame(8000, (int) $row->wallet_transactions_sum_amount_cents);
    }

    public function test_each_new_relation_manager_renders(): void
    {
        $member = $this->member();
        $this->actingAs($this->actor(Role::OWNER));

        foreach ([ConsumptionRelationManager::class, VisitsRelationManager::class, SanctionsRelationManager::class, AvaladosRelationManager::class] as $rm) {
            Livewire::test($rm, ['ownerRecord' => $member, 'pageClass' => ViewMember::class])->assertSuccessful();
        }
    }
}
