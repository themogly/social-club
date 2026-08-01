<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\TillSummary;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class VoidDispensationTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true, // €10,00/g
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
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

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        // A real operator is ASSIGNED to the sede they work — the void policy now binds to it (prompt 75).
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    /** @param array<string,mixed> $options */
    private function commit(Member $member, int $gramsCg, array $options = []): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => $gramsCg]],
            $options,
        );
    }

    public function test_void_returns_stock_releases_grams_and_reverses_both_wallet_and_cash(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $member = $this->member();
        (new RecordWalletTransaction)->handle($member, $this->location, 5000, WalletTransactionType::TOPUP, ['allow_debt' => true]);
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        // 3.50 g @ €10,00/g = €35,00; tendered €15,00 cash + €20,00 wallet.
        $dispensation = $this->commit($member, 350, [
            'till_session_id' => $till->id, 'cash_cents' => 1500, 'wallet_cents' => 2000, 'operator_id' => $manager->id,
        ]);

        $this->assertSame(3500, $dispensation->total_cents->cents);
        $this->assertSame(99650, $this->batch->fresh()->remaining_cg->centigrams);       // 100000 − 350
        $this->assertSame(3000, Wallet::balance($member->id, $this->location->id));        // 5000 − 2000
        $this->assertSame(11500, TillSummary::expectedCents($till));                       // 10000 float + 1500 cash
        $this->assertSame(350, $member->dispensations()->completed()->first()?->lines->sum(fn ($l) => $l->grams_cg->centigrams));

        (new VoidDispensation)->handle($dispensation, $manager, 'Peso incorrecto');

        $this->assertSame(DispensationStatus::VOIDED, $dispensation->fresh()->status);
        $this->assertSame(100000, $this->batch->fresh()->remaining_cg->centigrams);        // stock returned
        $this->assertSame(5000, Wallet::balance($member->id, $this->location->id));         // wallet refunded
        $this->assertSame(10000, TillSummary::expectedCents($till));                        // cash dropped out
        $this->assertSame(0, $member->dispensations()->completed()->count());              // grams released
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispensation.voided']);
    }

    public function test_voiding_requires_the_permission(): void
    {
        $staff = $this->userWithRole(Role::STAFF); // pos.use, but NOT dispensation.void
        $member = $this->member();
        $dispensation = $this->commit($member, 100);

        $this->expectException(AuthorizationException::class);
        (new VoidDispensation)->handle($dispensation, $staff, 'nope');
    }

    public function test_a_manager_cannot_void_another_sedes_dispensation(): void
    {
        // The dispensation belongs to $this->location; this manager is assigned only to a DIFFERENT sede.
        // dispensation.void alone is not enough — the policy binds the actor to the row's location (prompt 75).
        $otherLocation = Location::factory()->create(['organisation_id' => $this->org->id]);
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$otherLocation->id]);

        $dispensation = $this->commit($this->member(), 100);

        $this->expectException(AuthorizationException::class);
        (new VoidDispensation)->handle($dispensation, $manager, 'cross-sede void attempt');
    }

    public function test_voiding_requires_a_reason(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $dispensation = $this->commit($this->member(), 100);

        $this->expectException(RuntimeException::class);
        (new VoidDispensation)->handle($dispensation, $manager, '   ');
    }

    public function test_a_dispensation_cannot_be_voided_twice(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $dispensation = $this->commit($this->member(), 100);
        (new VoidDispensation)->handle($dispensation, $manager, 'first');

        $this->expectException(RuntimeException::class);
        (new VoidDispensation)->handle($dispensation->fresh(), $manager, 'second');
    }

    public function test_a_correction_is_a_void_plus_a_new_linked_dispensation(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);
        $member = $this->member();
        $original = $this->commit($member, 300, ['operator_id' => $manager->id]);

        (new VoidDispensation)->handle($original, $manager, 'Wrong genetic');
        $correction = $this->commit($member, 250, ['operator_id' => $manager->id, 'reversal_of_id' => $original->id]);

        $this->assertSame(DispensationStatus::VOIDED, $original->fresh()->status);
        $this->assertSame(DispensationStatus::COMPLETED, $correction->status);
        $this->assertSame($original->id, $correction->reversal_of_id);
        $this->assertSame(250, $member->dispensations()->completed()->first()?->lines->sum(fn ($l) => $l->grams_cg->centigrams));
    }
}
