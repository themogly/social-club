<?php

namespace Tests\Feature\Batches;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Dispensing\VoidDispensation;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\ListBatches;
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
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\BatchRecall;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 86 — batch recall. The recall list is the inverse of the traceability spine: one batch → every
 * member who received product from it, once each, with total, date range and contact details. It errs
 * toward completeness (voided/refunded INCLUDED and labelled), ignores the batch's own stock/status (the
 * whole point is a closed/empty/expired batch), spans sedes (by product, not home sede), and is gated on
 * `reports.view` — the consumption-data gate, narrower than the `stock.manage` gate that merely opens the
 * batch table.
 */
class BatchRecallTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->batch = $this->makeBatch();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeBatch(): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 1_000_000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay(),
        ]);
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
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function commit(Member $member, Batch $batch, int $gramsCg): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => $gramsCg]],
        );
    }

    /** @return array<string, array<string, mixed>> keyed by member_no */
    private function rowsByMemberNo(Batch $batch): array
    {
        $rows = [];
        foreach ((new BatchRecall($batch))->rows() as $row) {
            $rows[$row['member_no']] = $row;
        }

        return $rows;
    }

    public function test_each_affected_member_appears_once_with_the_correct_total_and_date_range(): void
    {
        // Create members before the batch history so their carencia has ended by the earliest commit.
        CarbonImmutable::setTestNow('2026-07-19 09:00:00');
        $a = $this->member();
        $b = $this->member();

        // Member A: two separate business days (each under the 350 cg daily cap) → one row, summed, spanned.
        CarbonImmutable::setTestNow('2026-07-20 10:00:00');
        $this->commit($a, $this->batch, 200);
        CarbonImmutable::setTestNow('2026-07-22 15:00:00');
        $this->commit($a, $this->batch, 150);
        $this->commit($b, $this->batch, 100);
        CarbonImmutable::setTestNow();

        $rows = $this->rowsByMemberNo($this->batch);

        $this->assertCount(2, $rows);
        $this->assertSame(350, $rows[$a->member_no]['gramos']);      // 200 + 150, once
        $this->assertSame(100, $rows[$b->member_no]['gramos']);
        $this->assertSame('2026-07-20', CarbonImmutable::parse($rows[$a->member_no]['primera'])->toDateString());
        $this->assertSame('2026-07-22', CarbonImmutable::parse($rows[$a->member_no]['ultima'])->toDateString());

        $totals = (new BatchRecall($this->batch))->totals();
        $this->assertSame(2, $totals['members']);
        $this->assertSame(450, $totals['grams_cg']);
        $this->assertSame('2026-07-20', $totals['first']?->toDateString());
        $this->assertSame('2026-07-22', $totals['last']?->toDateString());
    }

    public function test_a_member_who_received_a_different_batch_is_excluded(): void
    {
        $mine = $this->member();
        $other = $this->member();
        $otherBatch = $this->makeBatch();

        $this->commit($mine, $this->batch, 100);
        $this->commit($other, $otherBatch, 100);

        $rows = $this->rowsByMemberNo($this->batch);

        $this->assertArrayHasKey($mine->member_no, $rows);
        $this->assertArrayNotHasKey($other->member_no, $rows);
        $this->assertCount(1, $rows);
    }

    public function test_voided_and_refunded_lines_are_included_and_labelled(): void
    {
        $manager = $this->userWithRole(Role::MANAGER);

        $voided = $this->member();
        $voidedDispensation = $this->commit($voided, $this->batch, 200);
        (new VoidDispensation)->handle($voidedDispensation, $manager, 'Peso incorrecto');

        $refunded = $this->member();
        $refundedDispensation = $this->commit($refunded, $this->batch, 200);
        (new RefundDispensation)->handle($refundedDispensation, $manager, [
            'amount_cents' => 400, 'grams_cg' => 0,
            'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET, 'reason' => 'Mohoso',
        ]);

        $rows = $this->rowsByMemberNo($this->batch);

        // Completeness: neither is dropped — a recall must reach everyone who ever held the product.
        $this->assertArrayHasKey($voided->member_no, $rows);
        $this->assertArrayHasKey($refunded->member_no, $rows);
        $this->assertStringContainsString(__('anulada'), $rows[$voided->member_no]['estado']);
        $this->assertStringContainsString(__('reembolsada'), $rows[$refunded->member_no]['estado']);
    }

    public function test_a_closed_empty_or_expired_batch_still_returns_its_full_recall_list(): void
    {
        $member = $this->member();
        $this->commit($member, $this->batch, 200);

        // The batch is now drained, closed and past its expiry — exactly the recall case. The list ignores
        // stock/status entirely (these columns are not what recall reads), so the member still surfaces.
        $this->batch->forceFill([
            'status' => BatchStatus::CLOSED,
            'remaining_cg' => 0,
            'expires_on' => now()->subMonth(),
        ])->save();

        $rows = $this->rowsByMemberNo($this->batch->fresh());

        $this->assertArrayHasKey($member->member_no, $rows);
        $this->assertSame(200, $rows[$member->member_no]['gramos']);
    }

    public function test_the_csv_export_lists_the_same_members(): void
    {
        $a = $this->member();
        $b = $this->member();
        $this->commit($a, $this->batch, 200);
        $this->commit($b, $this->batch, 100);

        $csv = ReportExport::csv((new BatchRecall($this->batch))->table());

        $this->assertStringContainsString($a->member_no, $csv);
        $this->assertStringContainsString($b->member_no, $csv);
        $this->assertStringContainsString($a->last_name, $csv);
        $this->assertStringContainsString($b->last_name, $csv);
    }

    public function test_the_recall_is_by_product_across_sedes_not_narrowed_to_the_active_location(): void
    {
        $a = $this->member();
        $b = $this->member();
        $this->commit($a, $this->batch, 100);
        $this->commit($b, $this->batch, 100);

        // Point the active scope at a DIFFERENT sede: the recall is by product (this batch's lines),
        // org-wide, and must not shrink to whatever location the panel happens to be on.
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($other->id);

        $this->assertCount(2, (new BatchRecall($this->batch))->rows());
    }

    public function test_the_recall_action_is_hidden_from_a_batch_viewer_without_reports_view(): void
    {
        // A user who can open the batch table (stock.manage) but lacks the consumption-data gate.
        $stockOnly = User::factory()->create();
        $stockOnly->givePermissionTo('stock.manage');
        $stockOnly->locations()->sync([$this->location->id]);
        $this->actingAs($stockOnly);

        Livewire::test(ListBatches::class)
            ->assertTableActionHidden('recall', $this->batch);
    }

    public function test_the_recall_action_is_visible_to_a_reports_viewer(): void
    {
        $manager = $this->userWithRole(Role::MANAGER); // has both stock.manage and reports.view
        $this->actingAs($manager);

        Livewire::test(ListBatches::class)
            ->assertTableActionVisible('recall', $this->batch);
    }
}
