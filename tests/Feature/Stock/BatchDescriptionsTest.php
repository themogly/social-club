<?php

namespace Tests\Feature\Stock;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\TransferBatch;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Livewire\Counter\TillSession;
use App\Models\Batch;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\LoteSeqBackfill;
use App\ViewModels\BatchRecall;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 298 — batches say what they are: the strain first, then the club's name for the batch or an automatic
 * description (#n within the strain · received {date} · {quantity}); new batches get a readable lote number
 * (`AMN-260912-3`) unless the grow's own is typed; the lote number stays wherever traceability needs it.
 */
class BatchDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Location $otra;

    private User $owner;

    private Genetic $amnesia;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28 18:00:00');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->otra = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->actingAs($this->owner);
        $this->amnesia = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
    }

    /** @param  array<string, mixed>  $data */
    private function intake(?Genetic $genetic = null, array $data = []): Batch
    {
        return (new IntakeBatch)->handle($genetic ?? $this->amnesia, $this->sede, $data + ['grams' => '250', 'acquired_or_harvested_on' => '2026-09-12']);
    }

    // --- 1–3. Numbering ---------------------------------------------------------------------------------------------

    public function test_a_new_batch_gets_the_next_number_for_its_strain_and_a_readable_lote_number(): void
    {
        $first = $this->intake();
        $second = $this->intake(data: ['acquired_or_harvested_on' => '2026-10-03']);
        $other = $this->intake(Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Ñoño Kush']));
        $short = $this->intake(Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'O.G']));

        $this->assertSame([1, 2], [$first->lote_seq, $second->lote_seq]);
        $this->assertSame(['AMN-260912-1', 'AMN-261003-2'], [$first->batch_no, $second->batch_no]);
        $this->assertSame(['NON-260912-1', 1], [$other->batch_no, $other->lote_seq]);
        $this->assertSame('OGX-260912-1', $short->batch_no);
        $this->assertNotSame($first->lote_seq, $second->lote_seq);
    }

    public function test_a_generated_number_that_already_exists_gets_a_suffix(): void
    {
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->amnesia->id, 'location_id' => $this->sede->id,
            'batch_no' => 'AMN-260912-1', 'lote_seq' => null]);

        $this->assertSame('AMN-260912-1-2', $this->intake()->batch_no);
    }

    public function test_the_numbers_are_organisation_wide_not_per_sede(): void
    {
        $this->intake();
        $atNorte = (new IntakeBatch)->handle($this->amnesia, $this->otra, ['grams' => '10', 'acquired_or_harvested_on' => '2026-09-12']);

        $this->assertSame(2, $atNorte->lote_seq);
    }

    public function test_an_own_lote_number_is_used_and_a_duplicate_for_the_same_strain_refused(): void
    {
        $this->assertSame('GROW-17', $this->intake(data: ['batch_no' => '  GROW-17 '])->batch_no);
        $this->assertSame('GROW-17', $this->intake(Genetic::factory()->create(['organisation_id' => $this->org->id]), ['batch_no' => 'GROW-17'])->batch_no);

        $this->expectException(DomainException::class);
        $this->intake(data: ['batch_no' => 'GROW-17']);
    }

    public function test_the_add_stock_form_offers_the_own_number_and_refuses_a_duplicate_on_the_field(): void
    {
        $this->intake(data: ['batch_no' => 'GROW-17']);

        Livewire::test(CreateBatch::class)
            ->assertFormFieldExists('batch_no')
            ->fillForm(['genetic_id' => $this->amnesia->id, 'location_id' => $this->sede->id, 'grams' => '10', 'batch_no' => 'GROW-17', 'sale_price_eur' => '9'])
            ->call('create')
            ->assertHasFormErrors(['batch_no']);

        $this->assertSame(1, Batch::query()->withoutGlobalScopes()->where('batch_no', 'GROW-17')->count());
    }

    public function test_a_part_transfer_keeps_the_lote_number_and_its_place_in_the_strain(): void
    {
        $batch = $this->intake();

        $child = (new TransferBatch)->handle($batch, $this->otra, 5000, $this->owner);

        $this->assertNotSame($batch->id, $child->id);
        $this->assertSame([$batch->batch_no, $batch->lote_seq], [$child->batch_no, $child->lote_seq]);
    }

    // --- 4. The backfill ------------------------------------------------------------------------------------------

    public function test_the_backfill_numbers_lotes_per_strain_in_order_of_first_intake_and_keeps_every_lote_number(): void
    {
        $other = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $make = fn (Genetic $g, string $no, string $at, Location $where): Batch => Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $g->id, 'location_id' => $where->id, 'batch_no' => $no, 'lote_seq' => null,
            'created_at' => $at,
        ]);
        $late = $make($this->amnesia, 'B-ZZZZZZ', '2026-03-01', $this->sede);
        $early = $make($this->amnesia, 'B-AAAAAA', '2026-01-01', $this->sede);
        $earlyPart = $make($this->amnesia, 'B-AAAAAA', '2026-04-01', $this->otra); // a part of the early lote, moved later
        $mid = $make($this->amnesia, 'B-MMMMMM', '2026-02-01', $this->sede);
        $otherFirst = $make($other, 'B-OTHER1', '2026-05-01', $this->sede);
        $numbers = Batch::query()->withoutGlobalScopes()->pluck('batch_no', 'id')->all();

        LoteSeqBackfill::run();

        $seq = fn (Batch $b): ?int => $b->fresh()->lote_seq;
        $this->assertSame([1, 1, 2, 3], [$seq($early), $seq($earlyPart), $seq($mid), $seq($late)]);
        $this->assertSame(1, $seq($otherFirst));
        $this->assertSame($numbers, Batch::query()->withoutGlobalScopes()->pluck('batch_no', 'id')->all(), 'the backfill changed a lote number');

        LoteSeqBackfill::run(); // idempotent
        $this->assertSame(3, $seq($late));
    }

    // --- 5. Display -------------------------------------------------------------------------------------------------

    public function test_strain_first_then_the_label_or_the_automatic_description(): void
    {
        $batch = $this->intake();
        $named = $this->intake(data: ['label' => 'Cosecha verano 2026']);
        $old = $this->intake(data: ['acquired_or_harvested_on' => '2025-12-03']);
        $units = $this->intake(Genetic::factory()->preroll()->create(['organisation_id' => $this->org->id, 'name' => 'Porro Clásico']), ['units' => 40, 'acquired_or_harvested_on' => '2026-10-03']);

        $this->assertSame('Amnesia Haze', $batch->displayTitle());
        $this->assertSame('Cosecha verano 2026', $named->displaySubtitle());
        $this->assertSame('Amnesia Haze · Cosecha verano 2026', $named->displayName());

        app()->setLocale('es');
        $this->assertSame('#1 · entrada 12 sep · 250,00 g', $batch->displaySubtitle());
        $this->assertSame('#3 · entrada 3 dic 2025 · 250,00 g', $old->displaySubtitle());
        $this->assertSame('#1 · entrada 3 oct · 40 uds', $units->displaySubtitle());
        $this->assertSame('#1 · 12 sep', $batch->displaySubtitle(short: true));
        $this->assertSame('Amnesia Haze · #1 · entrada 12 sep · 250,00 g', $batch->displayName());

        app()->setLocale('en');
        $this->assertSame('#1 · received 12 Sep · 250.00 g', $batch->displaySubtitle());
        $this->assertSame('#1 · received 3 Oct · 40 units', $units->displaySubtitle());

        $this->assertStringNotContainsString($batch->batch_no, $batch->displayName());
    }

    // --- 6. The counter never shows a lote number ---------------------------------------------------------------------

    public function test_the_lote_chip_shows_the_subtitle_and_never_the_lote_number(): void
    {
        app()->setLocale('es'); // the Spanish description is asserted; the English one has its own test
        $batch = $this->intake();
        $named = $this->intake(data: ['label' => 'Cosecha verano']);

        $chip = (string) $this->blade('<x-counter.batch-chip :batch="$batch" quantity="250 g" />', ['batch' => $batch]);
        $this->assertStringContainsString('#1 · 12 sep', $chip);
        $this->assertStringNotContainsString($batch->batch_no, $chip);

        $chip = (string) $this->blade('<x-counter.batch-chip :batch="$batch" quantity="250 g" />', ['batch' => $named]);
        $this->assertStringContainsString('Cosecha verano', $chip);
        $this->assertStringNotContainsString($named->batch_no, $chip);
    }

    public function test_the_closing_recount_lists_batches_by_strain_and_description_without_the_lote_number(): void
    {
        app()->setLocale('es'); // the Spanish description is asserted; the English one has its own test
        $batch = $this->intake();
        $batch->forceFill(['remaining_cg' => 20000])->save(); // touched since intake ⇒ in the recount

        $html = $this->recountHtml();

        $this->assertStringContainsString('Amnesia Haze · #1 · entrada 12 sep', $html);
        $this->assertStringNotContainsString($batch->batch_no, $html);
    }

    private function recountHtml(): string
    {
        $this->post(route('counter.location'), ['location_id' => $this->sede->id]); // two sedes: the counter's own choice
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);

        return (string) Livewire::test(TillSession::class)->call('startClose')->assertSet('reweighing', true)->html();
    }

    // --- 7. The batches list --------------------------------------------------------------------------------------

    public function test_the_list_leads_with_the_strain_hides_the_lote_number_and_searches_all_three(): void
    {
        app()->setLocale('es'); // the Spanish description is asserted; the English one has its own test
        $plain = $this->intake();
        $named = $this->intake(data: ['label' => 'Cosecha verano', 'batch_no' => 'GROW-17']);
        $other = $this->intake(Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Critical']));

        Livewire::test(ListBatches::class)
            ->assertTableColumnExists('lote')
            ->assertCanNotRenderTableColumn('batch_no')
            ->assertSee('#1 · entrada 12 sep')
            ->searchTable('amnesia')->assertCanSeeTableRecords([$plain, $named])->assertCanNotSeeTableRecords([$other])
            ->searchTable('verano')->assertCanSeeTableRecords([$named])->assertCanNotSeeTableRecords([$plain, $other])
            ->searchTable('GROW-17')->assertCanSeeTableRecords([$named])->assertCanNotSeeTableRecords([$plain, $other])
            ->searchTable('AMN')->assertCanSeeTableRecords([$plain])->assertCanNotSeeTableRecords([$other]);
    }

    // --- 8. Where the lote number stays ---------------------------------------------------------------------------

    public function test_the_recall_the_registro_and_the_snapshot_keep_the_lote_number(): void
    {
        $batch = $this->intake(data: ['label' => 'Cosecha verano']);
        $this->owner->locations()->sync([$this->sede->id]);
        $till = (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->sede->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);
        $batch->forceFill(['price_per_gram_cents' => 900])->save();
        (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $this->amnesia->id, 'batch_id' => $batch->id, 'grams_cg' => 100]],
            ['operator_id' => $this->owner->id, 'till_session_id' => $till->id, 'cash_cents' => 900, 'wallet_cents' => 0]);

        $this->assertSame($batch->batch_no, DispensationLine::query()->withoutGlobalScopes()->sole()->batch_no_snapshot);

        $recall = new BatchRecall($batch->fresh());
        $this->assertSame([$batch->batch_no], array_column($recall->rows(), 'lote'));
        $this->assertStringContainsString($batch->batch_no, $recall->table()->title);
    }

    public function test_a_unit_product_gets_a_readable_lote_number_too(): void
    {
        $edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Brownie', 'product_type' => ProductType::EDIBLE, 'grams_per_unit_cg' => 100]);

        $this->assertStringStartsWith('BRO-', $this->intake($edible, ['units' => 3])->batch_no);
    }
}
