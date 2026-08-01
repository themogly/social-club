<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Bar\CommitOrder;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 123 — the idempotency guarantee is the UNIQUE INDEX; the pre-check is an optimisation for the common
 * non-concurrent retry. Under a true race both requests miss the pre-check and both insert; the loser used to
 * die with a raw UniqueConstraintViolationException on the one screen where an operator must know whether a
 * sale went through. It now takes the pre-check's path instead: re-read the row for that key and return it.
 *
 * A genuine OS-level race can't be reproduced single-process (see ConcurrencyLocksTest). The catch is exercised
 * DETERMINISTICALLY instead by injecting the concurrent winner between the racer's pre-check and its insert (a
 * one-shot `creating` hook), which is exactly the window the unique index guards.
 */
class IdempotencyRaceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
        $this->article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 250, 'stock' => 100, 'active' => true,
        ]);
    }

    private function operator(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::MANAGER->value);
        $u->locations()->sync([$this->location->id]);

        return $u;
    }

    private function member(): Member
    {
        $m = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $m->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $m;
    }

    private function till(User $op): TillSession
    {
        return (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $op->id]);
    }

    // --- The unique index IS the guarantee -------------------------------------------

    public function test_the_idempotency_key_is_uniquely_indexed_on_both_tables(): void
    {
        foreach (['dispensations', 'orders'] as $table) {
            $indexes = collect(Schema::getIndexes($table));
            $this->assertTrue(
                $indexes->contains(fn (array $i): bool => $i['unique'] && in_array('idempotency_key', $i['columns'], true)),
                "{$table} must carry a UNIQUE index on idempotency_key — the real guarantee.",
            );
        }
    }

    // --- Sequential retry: the pre-check path, unchanged ------------------------------

    public function test_a_sequential_retry_with_the_same_key_returns_the_original_and_moves_stock_once(): void
    {
        $op = $this->operator();
        $member = $this->member();
        $till = $this->till($op);
        $key = (string) Str::ulid();
        $stockBefore = $this->batch->refresh()->remaining_cg->centigrams;

        $first = (new CommitDispensation)->handle($member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 350]],
            ['till_session_id' => $till->id, 'cash_cents' => 3500, 'operator_id' => $op->id, 'idempotency_key' => $key]);

        $second = (new CommitDispensation)->handle($member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 350]],
            ['till_session_id' => $till->id, 'cash_cents' => 3500, 'operator_id' => $op->id, 'idempotency_key' => $key]);

        $this->assertSame($first->id, $second->id);              // same row, not a second
        $this->assertSame(1, Dispensation::query()->count());
        $this->assertSame($stockBefore - 350, $this->batch->refresh()->remaining_cg->centigrams); // moved ONCE
    }

    // --- The race path: the loser returns the winner without throwing -----------------

    public function test_a_race_losing_order_returns_the_winner_and_does_not_throw(): void
    {
        $op = $this->operator();
        $till = $this->till($op);
        $key = (string) Str::ulid();

        // The WINNER: a real prior commit for this key (moved stock + posted cash once).
        $winner = (new CommitOrder)->handle($this->location, [['article_id' => $this->article->id, 'qty' => 1]], [
            'till_session_id' => $till->id, 'operator_id' => $op->id, 'cash_cents' => 250, 'idempotency_key' => $key,
        ]);
        $stockAfterWinner = $this->article->refresh()->stock;

        // The RACER: force its fast-path pre-check to MISS (exactly what a true race does), so it proceeds to
        // insert and hits the unique index — landing in the catch.
        $racer = new class extends CommitOrder
        {
            protected function findByIdempotencyKey(?string $key): ?Order
            {
                return null;
            }
        };

        $result = $racer->handle($this->location, [['article_id' => $this->article->id, 'qty' => 1]], [
            'till_session_id' => $till->id, 'operator_id' => $op->id, 'cash_cents' => 250, 'idempotency_key' => $key,
        ]);

        // No raw exception; the loser got the WINNER's row back; one row; the racer's stock move rolled back.
        $this->assertSame($winner->id, $result->id);
        $this->assertSame(1, Order::query()->where('idempotency_key', $key)->count());
        $this->assertSame($stockAfterWinner, $this->article->refresh()->stock);
    }

    public function test_a_race_losing_dispensation_returns_the_winner_and_does_not_throw(): void
    {
        $op = $this->operator();
        $member = $this->member();
        $till = $this->till($op);
        $key = (string) Str::ulid();

        $winner = (new CommitDispensation)->handle($member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 350]],
            ['till_session_id' => $till->id, 'cash_cents' => 3500, 'operator_id' => $op->id, 'idempotency_key' => $key]);
        $stockAfterWinner = $this->batch->refresh()->remaining_cg->centigrams;

        $racer = new class extends CommitDispensation
        {
            protected function findByIdempotencyKey(?string $key): ?Dispensation
            {
                return null;
            }
        };

        $result = $racer->handle($member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 350]],
            ['till_session_id' => $till->id, 'cash_cents' => 3500, 'operator_id' => $op->id, 'idempotency_key' => $key]);

        $this->assertSame($winner->id, $result->id);
        $this->assertSame(1, Dispensation::query()->where('idempotency_key', $key)->count());
        $this->assertSame($stockAfterWinner, $this->batch->refresh()->remaining_cg->centigrams); // racer's decrement rolled back
    }
}
