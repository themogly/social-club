<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\RelationManagers\WalletTransactionsRelationManager;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 49 — the manual counterpart to the nightly sweep: a manager moves a member's credit between
 * sedes (the only way credit crosses a ring-fenced sede). Gated on wallet.adjust, with the standard
 * denial test, and it must never manufacture debt at the source.
 */
class ManualWalletTransferTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 10000, SettingType::CENTS);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id]);
        (new RecordWalletTransaction)->handle($this->member, $this->a, 5000, WalletTransactionType::TOPUP);
    }

    private function actor(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->a->id, $this->b->id]);

        return $user;
    }

    public function test_a_manager_transfers_credit_between_sedes(): void
    {
        $this->actingAs($this->actor(Role::MANAGER));

        Livewire::test(WalletTransactionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
            ->callAction('transfer', [
                'from_location_id' => $this->a->id,
                'to_location_id' => $this->b->id,
                'amount_eur' => '20.00',
                'reason' => 'Liquidación manual',
            ]);

        $this->assertSame(3000, Wallet::balance($this->member->id, $this->a->id));   // €20 out
        $this->assertSame(2000, Wallet::balance($this->member->id, $this->b->id));   // €20 in
    }

    public function test_it_refuses_to_transfer_more_than_the_source_credit(): void
    {
        $this->actingAs($this->actor(Role::MANAGER));

        Livewire::test(WalletTransactionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
            ->callAction('transfer', [
                'from_location_id' => $this->a->id,
                'to_location_id' => $this->b->id,
                'amount_eur' => '80.00', // only €50 available — must not manufacture debt at source
                'reason' => 'Demasiado',
            ]);

        $this->assertSame(5000, Wallet::balance($this->member->id, $this->a->id)); // untouched
        $this->assertSame(0, Wallet::balance($this->member->id, $this->b->id));
    }

    public function test_staff_without_wallet_adjust_cannot_see_the_transfer_action(): void
    {
        $this->actingAs($this->actor(Role::STAFF));

        Livewire::test(WalletTransactionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditMember::class])
            ->assertActionHidden('transfer');
    }
}
