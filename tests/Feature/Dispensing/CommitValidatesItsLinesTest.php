<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Counter\CommitCombinedSettle;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\CashMovement;
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
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 256 — a dispensation line is validated where it is COMMITTED, not only where the basket is built.
 *
 * `DispensaryPos::$basket` is a public Livewire property: the browser can set it to anything before
 * `commitDispensation()`. `addLine()` refuses a non-positive weight and resolves the right lote, but the commit
 * re-read the raw line and re-checked neither. So a forged `grams_cg: -500` on a manual lote committed a NEGATIVE
 * contribution — the lote's stock rose, cash was recorded leaving the till, the member's daily allowance went
 * up — and a manual lote of another genetic or another sede was drawn from as if it were the product sold. The
 * writer must hold the invariant, not the UI.
 */
class CommitValidatesItsLinesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Member $member;

    private User $operator;

    private TillSession $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        $this->genetic = $this->genetic('Amnesia Haze');

        $this->operator = User::factory()->create();
        $this->operator->assignRole(Role::STAFF->value);
        $this->operator->locations()->sync([$this->location->id]);
        $this->actingAs($this->operator);
        CounterOperator::set($this->operator);

        $this->till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $this->member = $this->member();
    }

    private function genetic(string $name): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => null,
            'tier_id' => null, 'price_per_gram_cents' => 723, 'active' => true,
        ]);

        return $genetic;
    }

    private function batch(Genetic $genetic, ?Location $location = null, int $remainingCg = 5000): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => ($location ?? $this->location)->id, 'remaining_cg' => $remainingCg,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);

        return $member;
    }

    private function dailyUsedCg(): int
    {
        return (new ResolveMemberLimits)->handle($this->member, $this->location)->dailyUsedCg;
    }

    /** @param list<array<string, mixed>> $lines */
    private function commit(array $lines): Dispensation
    {
        return (new CommitDispensation)->handle($this->member, $this->location, $lines, [
            'operator_id' => $this->operator->id, 'till_session_id' => $this->till->id,
        ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count(), 'a dispensation was written');
        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->where('till_session_id', $this->till->id)
            ->where('type', '!=', 'FLOAT')->count(), 'cash moved');
        $this->assertSame(0, $this->dailyUsedCg(), "the member's daily allowance moved");
    }

    // --- 1. Negative weight, manual lote — the forged basket, through the real screen -------------------------

    public function test_a_forged_negative_weight_on_a_manual_lote_is_refused_and_writes_nothing(): void
    {
        $batch = $this->batch($this->genetic, remainingCg: 150);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->set('basket', [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => -500, 'units' => null]])
            ->call('commitDispensation')
            ->assertSet('flashType', 'error');

        $this->assertNothingWritten();
        $this->assertSame(150, $batch->fresh()->remaining_cg->centigrams, 'stock was created from nothing');
    }

    public function test_the_writer_refuses_a_non_positive_weight_before_anything_else(): void
    {
        $batch = $this->batch($this->genetic);

        foreach ([-500, 0] as $grams) {
            try {
                $this->commit([['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => $grams]]);
                $this->fail("A {$grams} cg line committed.");
            } catch (RuntimeException $e) {
                $this->assertSame(__('Cada línea necesita una cantidad positiva.'), $e->getMessage());
            }
        }

        $this->assertNothingWritten();
        $this->assertSame(5000, $batch->fresh()->remaining_cg->centigrams);
    }

    // --- 2. Negative weight, automatic — refused by the guard, not by luck ------------------------------------

    public function test_a_negative_weight_in_automatic_is_refused_by_the_positivity_guard(): void
    {
        $this->batch($this->genetic, remainingCg: 100000); // ample stock: allocation would not be the refuser

        $this->expectExceptionMessage(__('Cada línea necesita una cantidad positiva.'));

        $this->commit([['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => -500]]);
    }

    // --- 3 & 4. A manual lote must belong to the line's genetic and this sede ---------------------------------

    public function test_a_manual_lote_of_another_genetic_is_refused(): void
    {
        $other = $this->batch($this->genetic('White Widow'));

        try {
            $this->commit([['genetic_id' => $this->genetic->id, 'batch_id' => $other->id, 'grams_cg' => 100]]);
            $this->fail('A lote of another genetic was drawn from.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('El lote :batch no corresponde a este producto en esta sede.', ['batch' => $other->batch_no]), $e->getMessage());
        }

        $this->assertNothingWritten();
        $this->assertSame(5000, $other->fresh()->remaining_cg->centigrams);
    }

    public function test_a_manual_lote_of_another_sede_is_refused(): void
    {
        $norte = Location::factory()->create(['organisation_id' => $this->org->id]);
        $norteBatch = $this->batch($this->genetic, $norte);

        try {
            $this->commit([['genetic_id' => $this->genetic->id, 'batch_id' => $norteBatch->id, 'grams_cg' => 100]]);
            $this->fail("Another sede's lote was drawn from by this counter.");
        } catch (RuntimeException $e) {
            $this->assertSame(__('El lote :batch no corresponde a este producto en esta sede.', ['batch' => $norteBatch->batch_no]), $e->getMessage());
        }

        $this->assertNothingWritten();
        $this->assertSame(5000, $norteBatch->fresh()->remaining_cg->centigrams);
    }

    // --- 5. A well-formed line still commits, both ways --------------------------------------------------------

    public function test_a_well_formed_line_commits_manual_and_automatic(): void
    {
        $batch = $this->batch($this->genetic, remainingCg: 5000);

        $manual = $this->commit([['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 300]]);
        $this->assertSame(2169, $manual->total_cents->cents); // 3 g × €7.23
        $this->assertSame(4700, $batch->fresh()->remaining_cg->centigrams);

        $automatic = $this->commit([['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 100]]);
        $this->assertSame(723, $automatic->total_cents->cents);
        $this->assertSame(4600, $batch->fresh()->remaining_cg->centigrams);
        $this->assertSame(400, $this->dailyUsedCg());
    }

    // --- The combined settle inherits the writer's guard -------------------------------------------------------

    public function test_a_combined_settle_with_a_negative_dispensation_line_is_refused(): void
    {
        $batch = $this->batch($this->genetic);
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 250, 'stock' => 10, 'active' => true,
        ]);

        try {
            (new CommitCombinedSettle)->handle($this->member, $this->location,
                [['genetic_id' => $this->genetic->id, 'batch_id' => $batch->id, 'grams_cg' => -500]],
                [['article_id' => $article->id, 'qty' => 1]],
                ['operator_id' => $this->operator->id, 'till_session_id' => $this->till->id]);
            $this->fail('The combined settle committed a negative line.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('Cada línea necesita una cantidad positiva.'), $e->getMessage());
        }

        $this->assertNothingWritten();
        $this->assertSame(5000, $batch->fresh()->remaining_cg->centigrams);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), 'the bar half survived the refusal');
        $this->assertSame(10, $article->fresh()->stock);
    }

    // --- 6. The bar's misc line ----------------------------------------------------------------------------------

    /**
     * The prompt's case — a negative misc line driving an order total NEGATIVE — did not reproduce through the
     * screen: the tender check refuses a negative cash figure first ("El desglose de pago… no cuadra"). Pinned
     * here so that stays true, but it is the tender check holding, not the writer.
     */
    public function test_a_forged_negative_only_misc_line_is_refused_at_the_bar(): void
    {
        Livewire::test(BarPos::class)
            ->set('basket', [['type' => 'misc', 'description' => 'Propina', 'unit_price_cents' => -500, 'qty' => 1, 'reference' => 'Evento']])
            ->call('commitOrder')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), 'a negative bar order was written');
    }

    /**
     * What DID reproduce: a forged negative misc line beside a real article. The total stays positive, so the
     * tender check passes, and `CommitOrder` wrote the negative line — an off-book discount the UI can never
     * produce (`addMiscLine()` refuses ≤ 0). Same shape as the dispensation weight: the writer trusted the line.
     */
    public function test_a_forged_negative_misc_line_beside_an_article_is_refused_by_the_writer(): void
    {
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Tónica', 'price_cents' => 500, 'stock' => 10, 'active' => true,
        ]);

        Livewire::test(BarPos::class)
            ->set('basket', [
                ['type' => 'article', 'article_id' => $article->id, 'qty' => 1],
                ['type' => 'misc', 'description' => 'Descuento', 'unit_price_cents' => -300, 'qty' => 1, 'reference' => 'Amigo'],
            ])
            ->set('cashTendered', '5')
            ->call('commitOrder')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), 'an order with a negative line was written');
        $this->assertSame(10, $article->fresh()->stock);
    }
}
