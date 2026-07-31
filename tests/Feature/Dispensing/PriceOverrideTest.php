<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Exceptions\LimitExceededException;
use App\Models\AuditLog;
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
use App\Support\Money;
use App\Support\Period;
use App\ViewModels\Reports\ConsumptionReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 64 — a permissioned, reasoned price override at the counter (comp defective product, a €0
 * give-away). It changes the CHARGED total only; the resolved figure is kept, every override is audited
 * and reportable, and it NEVER bypasses limits/eligibility. Money is asserted in real stored cents.
 */
class PriceOverrideTest extends TestCase
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

    private function authoriser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // has dispensation.price.override

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

    public function test_an_override_changes_the_charge_and_stores_both_figures(): void
    {
        // 100 cg (1 g) resolves to €10.00; overridden to €6.00.
        $d = $this->commit($this->member(), 100, [
            'price_override_cents' => 600, 'price_override_reason' => 'Producto mohoso', 'price_override_by' => $this->authoriser(),
        ]);

        $this->assertSame(600, $d->total_cents->cents);          // charged
        $this->assertSame(1000, $d->original_total_cents->cents); // resolved, kept
        $this->assertSame(600, $d->cash_cents->cents);           // tender reconciles to the charged total
        $this->assertSame(0, $d->wallet_cents->cents);
    }

    public function test_a_zero_override_follows_the_same_path(): void
    {
        $d = $this->commit($this->member(), 100, [
            'price_override_cents' => 0, 'price_override_reason' => 'Regalo por mala calidad', 'price_override_by' => $this->authoriser(),
        ]);

        $this->assertSame(0, $d->total_cents->cents);
        $this->assertSame(1000, $d->original_total_cents->cents);
        $this->assertSame(0, $d->cash_cents->cents);
    }

    public function test_an_override_without_the_permission_is_denied_server_side(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // no dispensation.price.override

        $this->expectException(AuthorizationException::class);
        $this->commit($this->member(), 100, ['price_override_cents' => 600, 'price_override_reason' => 'x', 'price_override_by' => $staff]);
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->commit($this->member(), 100, ['price_override_cents' => 600, 'price_override_reason' => '   ', 'price_override_by' => $this->authoriser()]);
    }

    public function test_the_override_is_audited_with_resolved_overridden_reason_and_authoriser(): void
    {
        $manager = $this->authoriser();
        $this->commit($this->member(), 100, ['price_override_cents' => 600, 'price_override_reason' => 'Mohoso', 'price_override_by' => $manager]);

        $audit = AuditLog::query()->where('action', 'dispensation.price.override')->latest()->firstOrFail();
        $this->assertSame(1000, $audit->before['total_cents']);
        $this->assertSame(600, $audit->after['total_cents']);
        $this->assertSame('Mohoso', $audit->after['reason']);
        $this->assertSame($manager->id, $audit->after['authorised_by']);
    }

    public function test_a_member_over_their_limit_is_still_blocked_even_at_zero(): void
    {
        $member = $this->member();
        $this->commit($member, 340); // default daily limit 350 cg — 3.4 g used

        // A €0 override does NOT relax the limit (the limit check runs before pricing).
        $this->expectException(LimitExceededException::class);
        $this->commit($member, 100, ['price_override_cents' => 0, 'price_override_reason' => 'Regalo', 'price_override_by' => $this->authoriser()]);
    }

    public function test_the_report_totals_the_override_value_for_the_period(): void
    {
        $this->commit($this->member(), 100, ['price_override_cents' => 600, 'price_override_reason' => 'x', 'price_override_by' => $this->authoriser()]);

        $summary = (new ConsumptionReport($this->org->id, [$this->location->id], Period::thisMonth()))->summary();
        $byLabel = array_column($summary, 'value', 'label');

        // €10.00 resolved − €6.00 charged = €4.00 forgone.
        $this->assertSame(Money::fromCents(400)->formatted(), $byLabel[__('Ajustes de precio')]);
    }
}
