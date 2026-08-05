<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
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
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dispensary POS screen — a THIN Livewire shell over CommitDispensation. These
 * pin the shell's guarantees: the pos.use gate, the "no cannabis line without a
 * member" rule, the happy path (one Dispensation, right money + stock), a BLOCK-
 * ineligible refusal, the offline fail-closed guard, and the authorization-checked
 * contribution receipt. The domain arithmetic itself is pinned in DispensaryPosTest.
 */
class DispensaryPosScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Test']);
    }

    // --- Fixtures --------------------------------------------------------------

    /** A STAFF operator (STAFF holds pos.use) assigned to the sede, active location set. */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user); // PIN-identified operator (prompt 26 guard)

        return $user;
    }

    private function priceAt(int $centsPerGram): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => $centsPerGram, 'active' => true,
        ]);
    }

    private function batch(int $remainingCg): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => $remainingCg,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /** Active socio but with NO membership at this sede → counter membership rule BLOCKs. */
    private function unaffiliatedMember(): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
    }

    private function openTill(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    // --- Tests -----------------------------------------------------------------

    public function test_the_screen_renders_for_a_pos_operator(): void
    {
        $this->priceAt(1000);
        $this->batch(100000);
        $this->operator();

        Livewire::test(DispensaryPos::class)
            ->assertOk()
            ->assertSet('noLocation', false)
            ->assertSee(__('Escanear tarjeta o buscar socio'))
            ->assertSee($this->genetic->name); // the genetics grid lists the priced sede genetic
    }

    public function test_a_user_without_pos_use_is_forbidden(): void
    {
        $user = User::factory()->create(); // no role → no pos.use
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->get(route('counter.pos'))->assertForbidden();
    }

    public function test_a_commit_without_a_member_creates_no_dispensation(): void
    {
        $this->priceAt(1000);
        $this->batch(100000);
        $this->openTill();
        $this->operator();

        // No member identified: invoking commit must be a no-op — the "no cannabis line
        // without a member" rule, enforced in the component guard.
        Livewire::test(DispensaryPos::class)
            ->assertSet('memberId', null)
            ->call('commit')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_happy_path_commits_exactly_one_dispensation_with_the_right_money_and_stock(): void
    {
        $this->priceAt(1000); // €10,00/g
        $batch = $this->batch(100000);
        $this->openTill();
        $this->operator();
        $member = $this->eligibleMember();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->assertSet('memberId', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->assertSet('activeBatchId', $batch->id) // FEFO defaulted
            ->set('weightInput', '3.5')
            ->call('addLine')
            ->assertCount('basket', 1)
            ->call('commit')
            ->assertSet('flashType', 'success');

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());

        $dispensation = Dispensation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(3500, $dispensation->total_cents->cents);   // €35,00
        $this->assertSame(3500, $dispensation->cash_cents->cents);    // full cash by default
        $this->assertSame(0, $dispensation->wallet_cents->cents);
        $this->assertSame($member->id, $dispensation->member_id);
        $this->assertSame(DispensationStatus::COMPLETED, $dispensation->status);
        $this->assertSame(99650, $batch->fresh()->remaining_cg->centigrams); // −350 cg
    }

    public function test_a_non_numeric_price_override_is_rejected_not_a_free_dispensation(): void
    {
        // Code-style audit: a garbage price-override entry used to (float)-cast to 0 → a €0 dispensation.
        $this->priceAt(1000); // €10,00/g
        $this->batch(100000);
        $this->openTill();
        $operator = $this->operator();
        $operator->givePermissionTo('dispensation.price.override'); // the override is permissioned
        $member = $this->eligibleMember();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '3.5')
            ->call('addLine')
            ->assertCount('basket', 1)
            ->set('priceOverrideEuros', 'abc')            // not a number
            ->set('priceOverrideReason', 'Producto defectuoso')
            ->call('commit')
            ->assertSet('flashType', 'error');            // rejected, never priced at zero

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_block_ineligible_member_cannot_commit_and_the_reason_is_shown(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $this->openTill();
        $this->operator();
        $member = $this->unaffiliatedMember(); // no active membership → membership BLOCK

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->assertSee(__('Sin membresía activa en esta sede.')) // the blocking reason is on screen
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->assertCount('basket', 1)
            ->call('commit')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(100000, $batch->fresh()->remaining_cg->centigrams); // stock untouched
    }

    public function test_an_offline_commit_is_refused_and_creates_no_dispensation(): void
    {
        $this->priceAt(1000);
        $this->batch(100000);
        $this->openTill();
        $this->operator();
        $member = $this->eligibleMember();

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '3.5')
            ->call('addLine')
            ->set('offline', true) // the Alpine listener would set this when the connection drops
            ->call('commit')
            ->assertSet('flashType', 'error')
            ->assertCount('basket', 1); // the basket survives — fail closed, not lost

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_receipt_renders_for_a_committed_dispensation(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $this->openTill();
        $operator = $this->operator();
        $member = $this->eligibleMember();

        $dispensation = (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 350]],
            ['operator_id' => $operator->id],
        );

        $this->get(route('counter.pos.receipt', $dispensation->id))
            ->assertOk()
            ->assertSee(__('Comprobante de aportación'))
            ->assertSee($member->fullName())
            ->assertSee($this->genetic->name)
            ->assertSee('35'); // the €35,00 total on the ticket
    }

    public function test_the_receipt_is_denied_to_a_user_without_permission(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $operator = $this->operator();
        $member = $this->eligibleMember();
        $dispensation = (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 350]],
            ['operator_id' => $operator->id],
        );

        $stranger = User::factory()->create(); // no role → no pos.use / reports.view
        $this->actingAs($stranger);

        $this->get(route('counter.pos.receipt', $dispensation->id))->assertForbidden();
    }

    public function test_the_receipt_is_denied_to_an_operator_at_another_location(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $operator = $this->operator();
        $member = $this->eligibleMember();
        $dispensation = (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 350]],
            ['operator_id' => $operator->id],
        );

        // A pos.use operator, but assigned only to ANOTHER sede → object-ownership denial.
        $otherLocation = Location::factory()->create(['organisation_id' => $this->org->id]);
        $otherOperator = User::factory()->create();
        $otherOperator->assignRole(Role::STAFF->value);
        $otherOperator->locations()->sync([$otherLocation->id]);
        $this->actingAs($otherOperator);

        $this->get(route('counter.pos.receipt', $dispensation->id))->assertForbidden();
    }

    public function test_staff_cannot_void_but_a_manager_can(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $operator = $this->operator(); // STAFF → no dispensation.void
        $member = $this->eligibleMember();
        $dispensation = (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 350]],
            ['operator_id' => $operator->id],
        );

        // STAFF: the void affordance refuses (permission gate), the row stays COMPLETED.
        Livewire::test(DispensaryPos::class)
            ->set('lastDispensationId', $dispensation->id)
            ->set('voidReason', 'Error de peso')
            ->call('voidLast')
            ->assertSet('flashType', 'error');
        $this->assertSame(DispensationStatus::COMPLETED, $dispensation->fresh()->status);

        // MANAGER: the same action voids and reverses stock.
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->location->id]);
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(DispensaryPos::class)
            ->set('lastDispensationId', $dispensation->id)
            ->set('voidReason', 'Corrección autorizada')
            ->call('voidLast')
            ->assertSet('flashType', 'success');

        $this->assertSame(DispensationStatus::VOIDED, $dispensation->fresh()->status);
        $this->assertSame(100000, $batch->fresh()->remaining_cg->centigrams); // grams returned
    }
}
