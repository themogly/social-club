<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exceptions\TillClosedException;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The dispensary POS is a thin Livewire shell over CommitDispensation; these pin the
 * money/weight/stock arithmetic, idempotency and the last-of-batch race at the
 * domain boundary the counter drives. (The screen itself is tested separately.)
 */
class DispensaryPosTest extends TestCase
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
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
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
            'location_id' => $this->location->id, 'remaining_cg' => $remainingCg, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000, // out of the way for these tests
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    /** @param array<string,mixed> $options */
    private function commit(Member $member, Batch $batch, int $gramsCg, array $options = []): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => $gramsCg]],
            $options,
        );
    }

    public function test_a_weight_based_dispensation_writes_money_stock_and_one_movement(): void
    {
        $this->priceAt(1000); // €10,00/g
        $batch = $this->batch(100000);
        $member = $this->member();

        $dispensation = $this->commit($member, $batch, 350); // 3.50 g

        $this->assertSame(3500, $dispensation->total_cents->cents);            // €35,00
        $this->assertCount(1, $dispensation->lines);
        $this->assertSame(3500, $dispensation->lines->first()->line_total_cents->cents);
        $this->assertSame(99650, $batch->fresh()->remaining_cg->centigrams);    // −350 cg
        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());

        $movements = StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_id', $batch->id)->where('type', StockMovementType::DISPENSE->value)->get();
        $this->assertCount(1, $movements);
        $this->assertSame(-350, $movements->first()->qty_cg->centigrams);
    }

    public function test_line_total_rounds_half_up_to_the_pinned_cent(): void
    {
        $this->priceAt(749); // €7,49/g
        $batch = $this->batch(100000);

        $dispensation = $this->commit($this->member(), $batch, 133); // 1.33 g

        // round_half_up(749 * 133 / 100) = round_half_up(996.17) = 996
        $this->assertSame(996, $dispensation->total_cents->cents);
    }

    public function test_a_double_submit_with_the_same_key_commits_exactly_once(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $member = $this->member();

        $first = $this->commit($member, $batch, 350, ['idempotency_key' => 'basket-abc']);
        $second = $this->commit($member, $batch, 350, ['idempotency_key' => 'basket-abc']);

        $this->assertSame($first->id, $second->id);                              // same row returned
        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(99650, $batch->fresh()->remaining_cg->centigrams);     // stock moved once only
    }

    public function test_two_dispensations_of_the_last_grams_only_one_succeeds(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(350); // exactly one 3.50 g withdrawal left
        $member = $this->member();

        $this->commit($member, $batch, 350); // takes the last of the batch

        try {
            $this->commit($member, $batch, 10); // nothing left — must be refused
            $this->fail('The second dispensation should have been refused for insufficient stock.');
        } catch (RuntimeException) {
            // expected — RecordStockMovement refuses to go negative
        }

        $this->assertSame(0, $batch->fresh()->remaining_cg->centigrams);
        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_committing_against_a_closed_till_session_is_refused(): void
    {
        $this->priceAt(1000);
        $batch = $this->batch(100000);
        $member = $this->member();

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        (new CloseTill)->handle($till, 10000, $manager);

        $this->expectException(TillClosedException::class);
        $this->commit($member, $batch, 350, ['till_session_id' => $till->id]);
    }
}
