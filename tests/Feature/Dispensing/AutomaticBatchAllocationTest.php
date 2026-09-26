<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
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
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 250 — the dispensary stops asking which lote: oldest first, split when the old one runs out.
 *
 * The jar is the unit the operator works with; the lote is the unit the register keeps. In automatic mode a
 * line's grams are drawn FEFO across the sede's batches, priced ONCE on the whole quantity, and stored as one
 * dispensation_lines row per batch drawn (the parts summing to the priced total exactly). The stock writer and
 * the per-line traceability are untouched.
 */
class AutomaticBatchAllocationTest extends TestCase
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

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    private function priceAt(int $centsPerGram): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => $centsPerGram, 'active' => true,
        ]);
    }

    /** A WEIGHT batch with an explicit acquisition date, so FEFO order is deterministic. */
    private function batch(int $remainingCg, string $acquiredOn, string $batchNo): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'batch_no' => $batchNo, 'remaining_cg' => $remainingCg, 'status' => BatchStatus::OPEN,
            'acquired_or_harvested_on' => $acquiredOn, 'expires_on' => now()->addYear(),
        ]);
    }

    private function member(): Member
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

    /** @param array<int, array<string, mixed>> $lines */
    private function commit(Member $member, User $operator, array $lines): Dispensation
    {
        return (new CommitDispensation)->handle($member, $this->location, $lines, ['operator_id' => $operator->id]);
    }

    // --- The split -------------------------------------------------------------

    public function test_a_line_splits_across_batches_oldest_first_and_prices_once(): void
    {
        $this->priceAt(1000); // €10,00/g
        $old = $this->batch(150, '2026-01-01', 'OLD-001');   // 1,50 g, older
        $new = $this->batch(5000, '2026-02-01', 'NEW-002');  // 50 g, newer
        $operator = $this->operator();
        $member = $this->member();

        // 3,00 g automatic (batch_id null) — the operator never chose a lote.
        $dispensation = $this->commit($member, $operator, [
            ['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 300],
        ]);

        $lines = $dispensation->lines()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $lines, 'the 3 g line did not split across the two batches');

        // Oldest first: 150 cg from OLD (emptying it), 150 cg from NEW.
        $this->assertSame($old->id, $lines[0]->batch_id);
        $this->assertSame(150, (int) $lines[0]->getRawOriginal('grams_cg'));
        $this->assertSame($new->id, $lines[1]->batch_id);
        $this->assertSame(150, (int) $lines[1]->getRawOriginal('grams_cg'));

        // Stock: the old lote is empty, the new one down 150 cg.
        $this->assertSame(0, $old->fresh()->remaining_cg->centigrams);
        $this->assertSame(4850, $new->fresh()->remaining_cg->centigrams);

        // One DISPENSE movement per batch drawn (the single writer, once per part).
        $this->assertSame(1, StockMovement::query()->withoutGlobalScopes()
            ->where('stockable_type', Batch::class)->where('stockable_id', $old->id)
            ->where('type', StockMovementType::DISPENSE->value)->count());

        // Priced ONCE on 3 g: €30,00, and the stored parts sum to it exactly.
        $this->assertSame(3000, $dispensation->total_cents->cents);
        $this->assertSame(3000, (int) $lines->sum(fn ($l): int => (int) $l->getRawOriginal('line_total_cents')));
    }

    public function test_the_parts_sum_to_the_priced_total_for_an_awkward_split(): void
    {
        $this->priceAt(999); // a rate that does not divide cleanly
        $this->batch(150, '2026-01-01', 'OLD-001');
        $this->batch(5000, '2026-02-01', 'NEW-002');
        $operator = $this->operator();
        $member = $this->member();

        $dispensation = $this->commit($member, $operator, [
            ['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 333], // 3,33 g
        ]);

        $lines = $dispensation->lines()->withoutGlobalScopes()->get();
        // Whatever the proportional split, the remainder lands on the last part so Σ = the priced total exactly.
        $this->assertSame(
            $dispensation->total_cents->cents,
            (int) $lines->sum(fn ($l): int => (int) $l->getRawOriginal('line_total_cents')),
            'the split parts do not sum to the priced total',
        );
        $this->assertSame(333, (int) $lines->sum(fn ($l): int => (int) $l->getRawOriginal('grams_cg')));
    }

    // --- Refused when the sede cannot cover it (rolled back) --------------------

    public function test_a_line_that_the_sede_cannot_cover_is_refused_and_rolls_back(): void
    {
        $this->priceAt(1000);
        $a = $this->batch(100, '2026-01-01', 'A-001');
        $b = $this->batch(100, '2026-02-01', 'B-002'); // 200 cg total, asking 300
        $operator = $this->operator();
        $member = $this->member();

        try {
            $this->commit($member, $operator, [
                ['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 300],
            ]);
            $this->fail('a short allocation should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->genetic->name, $e->getMessage()); // the genetic, not a lote
        }

        // Nothing persisted — the transaction rolled back.
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()
            ->where('type', StockMovementType::DISPENSE->value)->count());
        $this->assertSame(100, $a->fresh()->remaining_cg->centigrams);
        $this->assertSame(100, $b->fresh()->remaining_cg->centigrams);
    }

    // --- Manual mode is refused when the chosen lote does not fit (unchanged) ---

    public function test_manual_mode_still_refuses_a_line_that_does_not_fit_its_chosen_lote(): void
    {
        $this->priceAt(1000);
        $small = $this->batch(150, '2026-01-01', 'SMALL-001');
        $this->batch(5000, '2026-02-01', 'BIG-002');
        $operator = $this->operator();
        $member = $this->member();

        // Explicit batch_id (manual) that cannot hold 3 g → refused naming the lote (automatic must not leak in).
        try {
            $this->commit($member, $operator, [
                ['genetic_id' => $this->genetic->id, 'batch_id' => $small->id, 'grams_cg' => 300],
            ]);
            $this->fail('an over-full manual line should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SMALL-001', $e->getMessage());
        }

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    // --- UNIT genetics split in whole units -------------------------------------

    public function test_a_unit_genetic_splits_in_whole_units(): void
    {
        $unitGenetic = Genetic::factory()->create([
            'organisation_id' => $this->org->id, 'name' => 'Preroll Test',
            'product_type' => ProductType::PREROLL, 'grams_per_unit_cg' => 100,
        ]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $unitGenetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => null, 'price_per_unit_cents' => 500, 'active' => true,
        ]);
        $old = Batch::factory()->units(1)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $unitGenetic->id, 'location_id' => $this->location->id,
            'batch_no' => 'PR-OLD', 'status' => BatchStatus::OPEN, 'acquired_or_harvested_on' => '2026-01-01', 'expires_on' => now()->addYear(),
        ]);
        $new = Batch::factory()->units(5)->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $unitGenetic->id, 'location_id' => $this->location->id,
            'batch_no' => 'PR-NEW', 'status' => BatchStatus::OPEN, 'acquired_or_harvested_on' => '2026-02-01', 'expires_on' => now()->addYear(),
        ]);
        $operator = $this->operator();
        $member = $this->member();

        $dispensation = (new CommitDispensation)->handle($member, $this->location, [
            ['genetic_id' => $unitGenetic->id, 'batch_id' => null, 'units' => 3],
        ], ['operator_id' => $operator->id]);

        $lines = $dispensation->lines()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(1, (int) $lines[0]->units_dispensed); // the old batch's single unit
        $this->assertSame(2, (int) $lines[1]->units_dispensed); // the rest from the new batch
        // grams_cg is populated on every row (units × grams_per_unit_cg) — the load-bearing invariant.
        $this->assertSame(100, (int) $lines[0]->getRawOriginal('grams_cg'));
        $this->assertSame(200, (int) $lines[1]->getRawOriginal('grams_cg'));
        $this->assertSame(0, (int) $old->fresh()->remaining_units);
        $this->assertSame(3, (int) $new->fresh()->remaining_units);
    }

    // --- The pane: no Lote row in automatic, chips in manual ---------------------

    public function test_the_pane_shows_no_lote_control_in_automatic_and_the_sede_total(): void
    {
        $this->priceAt(1000);
        $this->batch(150, '2026-01-01', 'OLD-001');
        $this->batch(5000, '2026-02-01', 'NEW-002');
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $operator = $this->operator();
        $member = $this->member();

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->html();

        $this->assertStringContainsString('data-batch-mode="automatic"', $html);
        $this->assertStringNotContainsString('wire:click="selectBatch', $html, 'automatic mode must offer no lote control');
        $this->assertStringContainsString('In stock:', $html); // the sede total (EN locale)
    }

    public function test_the_pane_shows_the_lote_chips_in_manual(): void
    {
        Settings::set('dispensary_batch_selection', 'manual', SettingType::STRING, $this->location->id);
        $this->priceAt(1000);
        $this->batch(150, '2026-01-01', 'OLD-001');
        $this->batch(5000, '2026-02-01', 'NEW-002');
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $operator = $this->operator();
        $member = $this->member();

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->html();

        $this->assertStringContainsString('data-batch-mode="manual"', $html);
        $this->assertStringContainsString('wire:click="selectBatch', $html, 'manual mode must offer the lote chips');
    }

    // --- The register keeps a row per lote; the receipt shows one line -----------

    public function test_the_register_keeps_a_row_per_lote_but_the_receipt_shows_one_product_line(): void
    {
        $this->priceAt(1000);
        $this->batch(150, '2026-01-01', 'OLD-001');
        $this->batch(5000, '2026-02-01', 'NEW-002');
        $operator = $this->operator();
        $member = $this->member();

        $dispensation = $this->commit($member, $operator, [
            ['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 300],
        ]);

        // The register (dispensation_lines, one row per lote) keeps both lotes apart — the point of storing them.
        $lotes = $dispensation->lines()->withoutGlobalScopes()->pluck('batch_no_snapshot')->all();
        $this->assertEqualsCanonicalizing(['OLD-001', 'NEW-002'], $lotes);

        // The socio's receipt groups them into ONE product line (assert the name appears exactly once).
        $html = (string) $this->actingAs($operator)->get(route('counter.pos.receipt', $dispensation->id))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Amnesia Test'), 'the receipt shows two lines for one bag');
        // …and the one line carries the full 3 g.
        $this->assertStringContainsString('3', $html);
    }

    // --- Refund of a split defaults to the last (newest) lote --------------------

    public function test_refund_of_a_split_without_a_chosen_batch_returns_to_the_last_lote(): void
    {
        $this->priceAt(1000);
        $old = $this->batch(150, '2026-01-01', 'OLD-001'); // emptied by the split
        $new = $this->batch(5000, '2026-02-01', 'NEW-002');
        $operator = $this->operator();
        $member = $this->member();

        $dispensation = $this->commit($member, $operator, [
            ['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 300],
        ]);
        $this->assertSame(0, $old->fresh()->remaining_cg->centigrams);
        $this->assertSame(4850, $new->fresh()->remaining_cg->centigrams);

        // Refund 1 g with NO batch chosen — a split must not start asking for a lote; it returns to the LAST
        // stored row's batch (the newest, most likely still open).
        $refund = (new RefundDispensation)->handle($dispensation, $operator, [
            'amount_cents' => 1000, 'grams_cg' => 100, 'destination' => RefundDestination::STOCK,
            'method' => RefundMethod::WALLET, 'reason' => 'Devolución',
        ]);

        $this->assertSame($new->id, $refund->batch_id);
        $this->assertSame(4950, $new->fresh()->remaining_cg->centigrams); // 4850 + 100 back
        $this->assertSame(0, $old->fresh()->remaining_cg->centigrams);    // the old lote untouched
    }
}
