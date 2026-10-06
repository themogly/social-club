<?php

namespace Tests\Feature\Stock;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Stock\TopUpFromReserve;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\StockCeiling;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompts 355 + 359 on one branch (Ben: "2 big changes so needs lots of testing"): a day at the counter with the half-gram
 * rounding ON and a sealed reserve — the rounding never touches stock, the reserve never touches the price, and the
 * evening count reconciles to zero.
 */
class RoundingAndReserveTogetherTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_with_rounding_and_a_reserve_reconciles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$sede->id]);
        $this->actingAs($owner);
        $member = Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($member, $sede, MembershipTier::factory()->create(['organisation_id' => $org->id, 'discount_bp' => 0]), ['actor' => $owner, 'fee_cents' => 0]);
        $genetic = Genetic::factory()->create(['organisation_id' => $org->id, 'product_type' => ProductType::FLOWER]);
        $batch = Batch::factory()->create(['organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $sede->id,
            'initial_cg' => 1000, 'remaining_cg' => 200, 'reserve_cg' => 800, 'status' => BatchStatus::OPEN,
            'price_per_gram_cents' => 1000, 'price_per_eighth_cents' => 3200, 'expires_on' => now()->addYear()]);
        $sell = fn (int $cg) => (new CommitDispensation)->handle($member, $sede, [['genetic_id' => $genetic->id, 'batch_id' => null, 'grams_cg' => $cg]],
            ['operator_id' => $owner->id, 'charge_rounding' => true]);

        // 1.10 g from a 2.00 g jar: charged 1.0 g (€10), the jar falls by exactly 1.10 g, the reserve is untouched.
        $this->assertSame(1000, $sell(110)->total_cents->cents);
        $this->assertSame([90, 800], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);

        // Top up 5 g from the bags, then 3.40 g: charged 3.5 g → the eighth (€32); the jar falls by 3.40 g.
        (new TopUpFromReserve)->handle($batch->fresh(), 500, $owner);
        $this->assertSame(3200, $sell(340)->total_cents->cents);
        $this->assertSame([250, 300], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);

        // The legal ceiling sees the jar + the bags; nothing was "rounded" out of stock.
        $this->assertSame(550, StockCeiling::forLocation($sede)['on_site_cg']);
        $this->assertSame(1000 - 110 - 340, $batch->fresh()->remaining_cg->centigrams + $batch->fresh()->reserve_cg->centigrams);

        // The evening count weighs the jar (2.50 g) and reconciles to zero — no adjustment, bags untouched.
        $take = StockTake::create(['organisation_id' => $org->id, 'location_id' => $sede->id, 'opened_by' => $owner->id, 'opened_at' => now(), 'status' => StockTakeStatus::OPEN]);
        (new CommitStockTake)->handle($take, [['type' => 'batch', 'id' => $batch->id, 'counted' => 250]], $owner);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('stockable_id', $batch->id)->where('type', StockMovementType::ADJUSTMENT->value)->count());
        $this->assertSame([250, 300], [$batch->fresh()->remaining_cg->centigrams, $batch->fresh()->reserve_cg->centigrams]);
    }
}
