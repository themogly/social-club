<?php

namespace Tests\Feature\Pricing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Pricing\SetBatchPrice;
use App\Actions\Stock\TransferBatch;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\LocationKind;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BatchPriceBackfill;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 278 (Ben's 271) — the price and the photos are the BATCH's, not the strain's.
 *
 * Two harvests of one strain differ in quality, cost and look; the club prices and shows the batch it actually has. The
 * owner's three decisions are built on the recommended answers: a tier becomes a % discount (1a), a sale crossing into
 * the next batch prices each part at its own batch's price (2), and the price is per gram (3).
 */
class BatchPriceAndPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private User $owner;

    private TillSession $till;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Lemon Haze', 'published' => true]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->location->id]);
        $this->actingAs($this->owner);
        CounterOperator::set($this->owner);
        $this->till = (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function batch(int $priceCents, int $remainingCg, string $acquired, array $extra = []): Batch
    {
        return Batch::factory()->create(array_merge([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => $remainingCg, 'remaining_cg' => $remainingCg, 'status' => BatchStatus::OPEN,
            'expires_on' => now()->addYear(), 'acquired_or_harvested_on' => $acquired, 'price_per_gram_cents' => $priceCents,
        ], $extra));
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
            'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);

        return $member;
    }

    private function commit(int $gramsCg, ?string $batchId = null, ?Member $member = null): Dispensation
    {
        return (new CommitDispensation)->handle($member ?? $this->member(), $this->location, [
            ['genetic_id' => $this->genetic->id, 'batch_id' => $batchId, 'grams_cg' => $gramsCg],
        ], ['operator_id' => $this->owner->id, 'till_session_id' => $this->till->id]);
    }

    // --- 1–3. The batch's price is what is charged -------------------------------------------------------------------

    public function test_the_fefo_batch_price_is_charged_not_the_strains(): void
    {
        GeneticPrice::create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 1500, 'active' => true]);
        $this->batch(800, 5000, '2026-01-01');   // older → FEFO
        $this->batch(1000, 5000, '2026-06-01');

        $this->assertSame(800, $this->commit(100)->getRawOriginal('total_cents')); // 1 g at €8,00 (not the strain's €15)
    }

    public function test_a_sale_crossing_into_the_next_batch_prices_each_part_at_its_own_price(): void
    {
        $old = $this->batch(800, 100, '2026-01-01');    // only 1 g left at €8
        $new = $this->batch(1000, 5000, '2026-06-01');  // €10

        $dispensation = $this->commit(150); // 1,5 g → €8,00 + €5,00

        $this->assertSame(1300, $dispensation->getRawOriginal('total_cents'));
        $lines = DispensationLine::query()->where('dispensation_id', $dispensation->id)->get()->keyBy('batch_id');
        $this->assertSame(800, (int) $lines[$old->id]->getRawOriginal('line_total_cents'));
        $this->assertSame(800, (int) $lines[$old->id]->price_per_gram_cents);
        $this->assertSame(500, (int) $lines[$new->id]->getRawOriginal('line_total_cents'));
        $this->assertSame(1000, (int) $lines[$new->id]->price_per_gram_cents);
    }

    public function test_a_manually_chosen_lote_uses_its_own_price(): void
    {
        $this->batch(800, 5000, '2026-01-01');
        $chosen = $this->batch(1000, 5000, '2026-06-01');

        $this->assertSame(1000, $this->commit(100, $chosen->id)->getRawOriginal('total_cents'));
    }

    public function test_the_counter_says_before_commit_that_a_line_crosses_into_another_price(): void
    {
        $this->batch(800, 100, '2026-01-01');
        $this->batch(1000, 5000, '2026-06-01');

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member()->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '1,5')->call('addLine')
            ->assertSeeHtml('data-split-note')
            ->assertSet('basket.0.grams_cg', 150);
    }

    // --- 4. Transfers inherit -----------------------------------------------------------------------------------------

    public function test_a_transferred_part_inherits_the_price_and_can_be_repriced_alone(): void
    {
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN]);
        $source = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $store->id,
            'initial_cg' => 10000, 'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 900,
            'images' => ['batches/harvest.jpg'],
        ]);

        $child = (new TransferBatch)->handle($source, $this->location, 2500, $this->owner);
        $this->assertSame(900, $child->price_per_gram_cents);
        $this->assertSame(['batches/harvest.jpg'], $child->displayImages(), "the child does not show its parent's photo");

        (new SetBatchPrice)->handle($child, 1100, null, $this->owner);
        $this->assertSame(1100, $child->fresh()->price_per_gram_cents);
        $this->assertSame(900, $source->fresh()->price_per_gram_cents, 'repricing the child changed its parent');
        $this->assertTrue(AuditLog::query()->where('action', 'batch.price.updated')->exists());

        $child->forceFill(['images' => ['batches/own.jpg']])->save();
        $this->assertSame(['batches/own.jpg'], $child->fresh()->displayImages());
    }

    // --- 5. Unpriced cannot be dispensed ------------------------------------------------------------------------------

    public function test_a_batch_with_no_price_anywhere_cannot_be_dispensed(): void
    {
        $this->batch(800, 5000, '2026-01-01', ['price_per_gram_cents' => null]); // no batch price, no strain price

        $this->expectException(RuntimeException::class);
        $this->commit(100);
    }

    // --- 6. The migration's backfill ----------------------------------------------------------------------------------

    public function test_existing_batches_get_their_strains_base_price_and_the_unpriced_are_listed(): void
    {
        GeneticPrice::create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 750, 'price_per_eighth_cents' => 2300, 'active' => true]);
        $priced = $this->batch(0, 5000, '2026-01-01', ['price_per_gram_cents' => null]);
        $other = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $orphan = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $other->id, 'location_id' => $this->location->id, 'initial_cg' => 100, 'remaining_cg' => 100, 'status' => BatchStatus::OPEN]);

        $unpriced = BatchPriceBackfill::run();

        $this->assertSame(750, $priced->fresh()->price_per_gram_cents);
        $this->assertSame(2300, $priced->fresh()->price_per_eighth_cents);
        $this->assertSame([$orphan->id], array_column($unpriced, 'batch_id'));
        $this->assertNull($orphan->fresh()->price_per_gram_cents, 'a price was guessed');
    }

    // --- Decision 1a: the tier is a % discount -----------------------------------------------------------------------

    public function test_a_tier_is_a_percentage_discount_on_any_batch(): void
    {
        $this->tier->forceFill(['discount_bp' => 1000])->save(); // 10 %
        $this->batch(1000, 5000, '2026-01-01');

        $this->assertSame(900, $this->commit(100)->getRawOriginal('total_cents'));
    }

    // --- Photos: batch → strain → placeholder -------------------------------------------------------------------------

    public function test_the_member_menu_shows_the_next_batchs_photo_then_the_strains_then_a_placeholder(): void
    {
        $member = $this->member();
        $menu = fn () => $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk();

        $this->genetic->forceFill(['thc_bp' => 2250, 'cbd_bp' => 50])->save();
        $this->batch(800, 5000, '2026-01-01');
        $menu()->assertSee('THC 22.5%')->assertSee('CBD 0.5%'); // it read "—%" for every strain
        $menu()->assertSeeHtml('data-menu-photo-placeholder')->assertDontSee('loading="lazy" class="h-full w-full object-cover"', false);

        $this->genetic->forceFill(['images' => ['genetics/strain.jpg']])->save();
        $menu()->assertSee('genetics/strain.jpg');

        Batch::query()->withoutGlobalScopes()->update(['images' => json_encode(['batches/first.jpg'])]);
        $menu()->assertSee('batches/first.jpg')->assertDontSee('genetics/strain.jpg');

        // The first batch runs out; the next one (with its own photo) is shown.
        Batch::query()->withoutGlobalScopes()->update(['remaining_cg' => 0]);
        $this->batch(1000, 5000, '2026-06-01', ['images' => ['batches/second.jpg']]);
        $menu()->assertSee('batches/second.jpg')->assertDontSee('batches/first.jpg');
    }

    public function test_the_counter_shows_the_next_batchs_photo(): void
    {
        $this->batch(800, 5000, '2026-01-01', ['images' => ['batches/counter.jpg']]);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member()->id)
            ->assertSeeHtml('batches/counter.jpg');
    }
}
