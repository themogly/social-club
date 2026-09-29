<?php

namespace Tests\Feature\Stock;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\TransferBatch;
use App\Actions\Till\OpenTill;
use App\Enums\LocationKind;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Filament\Pages\RegistroDispensacion;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Livewire\Counter\TillSession;
use App\Models\AuditLog;
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
use App\ViewModels\BatchRecall;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 282 — batches get a name the club chooses ("Cosecha verano 2026"), and you can search by it.
 *
 * Every panel batch was called `B-7QX2KD` — the lote number, auto-generated and FIXED (it is the traceability key: the
 * registro de dispensación, the recall, a transfer's child and the ledger all hang on it). So the name is a separate,
 * free, editable field; it belongs to the lote (a rename reaches every part of it) and is never printed in the register.
 */
class BatchNamesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $store;

    private Genetic $amnesia;

    private Genetic $critical;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $this->amnesia = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        $this->critical = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Critical']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->attach([$this->centro->id, $this->store->id]);
        $this->actingAs($this->owner);
    }

    private function batch(Genetic $genetic, ?string $label, Location $at, string $batchNo = 'B-AAA111', string $grams = '100'): Batch
    {
        return (new IntakeBatch)->handle($genetic, $at, ['grams' => $grams, 'batch_no' => $batchNo, 'label' => $label, 'price_per_gram_cents' => 800]);
    }

    private function createForm(string $label): array
    {
        return [
            'location_id' => [$this->centro->id], // a choice of locations since 303
            'genetic_id' => $this->amnesia->id,
            'label' => $label,
            'grams' => '50',
            'sale_price_eur' => '8',
        ];
    }

    // 1 -------------------------------------------------------------------------------------------------------------

    public function test_a_name_is_stored_trimmed_and_a_blank_one_as_null(): void
    {
        Livewire::test(CreateBatch::class)->fillForm($this->createForm('  Cosecha verano 2026  '))->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateBatch::class)->fillForm($this->createForm('   '))->call('create')->assertHasNoFormErrors();

        $labels = Batch::query()->orderBy('created_at')->orderBy('id')->pluck('label')->all();
        $this->assertContains('Cosecha verano 2026', $labels);
        $this->assertContains(null, $labels);
        $this->assertCount(2, $labels);
    }

    // 2 -------------------------------------------------------------------------------------------------------------

    public function test_a_name_over_60_characters_is_refused_in_spanish(): void
    {
        app()->setLocale('es'); // the club's working language, whatever the suite's locale
        $component = Livewire::test(CreateBatch::class)->fillForm($this->createForm(str_repeat('a', 61)))->call('create')
            ->assertHasFormErrors(['label' => 'max']);

        $this->assertSame(0, Batch::query()->count());
        $message = collect($component->errors()->get('data.label'))->first();
        $this->assertSame(__('validation.max.string', ['attribute' => mb_strtolower(__('Nombre')), 'max' => 60], 'es'), $message);
    }

    // 3 -------------------------------------------------------------------------------------------------------------

    public function test_two_strains_can_share_a_name(): void
    {
        $this->batch($this->amnesia, 'Cosecha verano 2026', $this->centro, 'B-AAA111');
        $this->batch($this->critical, 'Cosecha verano 2026', $this->centro, 'B-BBB222');

        $this->assertSame(2, Batch::query()->where('label', 'Cosecha verano 2026')->count());
    }

    // 4 -------------------------------------------------------------------------------------------------------------

    public function test_a_part_transfer_copies_the_name_to_the_child(): void
    {
        $source = $this->batch($this->amnesia, 'Cosecha verano 2026', $this->store);

        $child = (new TransferBatch)->handle($source, $this->centro, 2500, $this->owner);

        $this->assertNotSame($source->id, $child->id);
        $this->assertSame('Cosecha verano 2026', $child->label);
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_renaming_one_part_renames_the_whole_lote_with_one_audit_entry(): void
    {
        $source = $this->batch($this->amnesia, 'Cosecha verano', $this->store, 'COSECHA-1', '1000');
        $child = (new TransferBatch)->handle($source, $this->centro, 25000, $this->owner);
        $otherLote = $this->batch($this->amnesia, 'Otra cosecha', $this->centro, 'COSECHA-2');

        Livewire::test(EditBatch::class, ['record' => $child->getRouteKey()])
            ->fillForm(['label' => 'Cosecha verano 2026'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertSee('Amnesia · Cosecha verano 2026'); // the page is titled by the display name (strain first, 298)

        $this->assertSame('Cosecha verano 2026', $source->fresh()->label);
        $this->assertSame('Cosecha verano 2026', $child->fresh()->label);
        $this->assertSame('Otra cosecha', $otherLote->fresh()->label);

        $audits = AuditLog::query()->where('action', 'batch.label.changed')->get();
        $this->assertCount(1, $audits);
        $this->assertSame('Cosecha verano', data_get($audits[0]->before, 'label'));
        $this->assertSame('Cosecha verano 2026', data_get($audits[0]->after, 'label'));
        $ids = (array) data_get($audits[0]->after, 'batch_ids');
        sort($ids);
        $expected = [$source->id, $child->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_saving_without_changing_the_name_writes_no_audit_entry(): void
    {
        $batch = $this->batch($this->amnesia, 'Cosecha verano', $this->centro);

        Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, AuditLog::query()->where('action', 'batch.label.changed')->count());
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    public function test_display_name_is_the_name_and_lote_or_the_lote_alone(): void
    {
        $named = $this->batch($this->amnesia, 'Cosecha verano 2026', $this->centro, 'B-7QX2KD');
        $bare = $this->batch($this->critical, null, $this->centro, 'B-9ZZ9ZZ');

        // Prompt 298 — the strain always leads; the lote number is kept for where traceability needs it.
        $this->assertSame('Amnesia · Cosecha verano 2026', $named->displayName());
        $this->assertStringStartsWith('Critical · ', $bare->displayName());
        $this->assertStringNotContainsString('B-9ZZ9ZZ', $bare->displayName());
        $this->assertSame('Amnesia · Cosecha verano 2026 · B-7QX2KD', $named->referenceName());
    }

    // 7 -------------------------------------------------------------------------------------------------------------

    public function test_the_lotes_table_finds_a_batch_by_part_of_its_name_case_insensitively(): void
    {
        $summer = $this->batch($this->amnesia, 'Cosecha Verano 2026', $this->centro, 'B-AAA111');
        $winter = $this->batch($this->critical, 'Cosecha invierno', $this->centro, 'B-BBB222');

        Livewire::test(ListBatches::class)
            ->searchTable('verano')
            ->assertCanSeeTableRecords([$summer])
            ->assertCanNotSeeTableRecords([$winter]);

        // The lote number and the strain are still found too.
        Livewire::test(ListBatches::class)->searchTable('bbb222')->assertCanSeeTableRecords([$winter])->assertCanNotSeeTableRecords([$summer]);
        Livewire::test(ListBatches::class)->searchTable('amnes')->assertCanSeeTableRecords([$summer])->assertCanNotSeeTableRecords([$winter]);
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    public function test_the_purchase_batch_picker_finds_a_batch_by_its_name(): void
    {
        $summer = $this->batch($this->amnesia, 'Cosecha verano 2026', $this->centro, 'B-AAA111');
        $this->batch($this->critical, 'Cosecha invierno', $this->centro, 'B-BBB222');

        $select = Livewire::test(CreatePurchase::class)->instance()->getSchemaComponent('form.batch_id');
        $this->assertInstanceOf(Select::class, $select);

        $results = $select->getSearchResultsForJs('verano');
        $this->assertCount(1, $results);
        $this->assertSame($summer->id, $results[0]['value']);
        $this->assertSame('Amnesia · Cosecha verano 2026', $results[0]['label']); // strain first, no lote number (298)
    }

    // 9 -------------------------------------------------------------------------------------------------------------

    public function test_the_manual_lote_chips_and_the_till_recount_show_the_name(): void
    {
        $batch = $this->batch($this->amnesia, 'Cosecha verano 2026', $this->centro, 'B-AAA111');

        $chip = Blade::render('<x-counter.batch-chip :batch="$batch" :selected="false" quantity="1 g" :fefo="true" />', ['batch' => $batch]);
        $this->assertStringContainsString('Cosecha verano 2026', $chip);
        $this->assertStringNotContainsString('B-AAA111', $chip); // the counter never shows a lote number (298)
        $this->assertStringContainsString('title="Amnesia · Cosecha verano 2026"', $chip);

        // The recount at the last close: touched flower batches, by name, ordered strain → name → lote.
        $batch->forceFill(['remaining_cg' => 9000])->saveQuietly();
        $spring = $this->batch($this->amnesia, 'Cosecha primavera', $this->centro, 'B-ZZZ999');
        $spring->forceFill(['remaining_cg' => 9000])->saveQuietly();
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)->call('startClose')->assertSet('reweighing', true)->html();
        $spring = strpos($html, 'Amnesia · Cosecha primavera');
        $summer = strpos($html, 'Amnesia · Cosecha verano 2026');
        $this->assertStringNotContainsString('B-AAA111', $html);
        $this->assertNotFalse($spring);
        $this->assertNotFalse($summer);
        $this->assertLessThan($summer, $spring, 'The recount is ordered strain → name.');
    }

    // 10 ------------------------------------------------------------------------------------------------------------

    public function test_a_dispensation_snapshots_the_lote_number_never_the_name(): void
    {
        $batch = $this->batch($this->amnesia, 'Cosecha verano 2026', $this->centro, 'B-AAA111');
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay()]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        (new CommitDispensation)->handle($member, $this->centro, [['genetic_id' => $this->amnesia->id, 'batch_id' => $batch->id, 'grams_cg' => 100]]);

        $line = DispensationLine::query()->sole();
        $this->assertSame('B-AAA111', $line->batch_no_snapshot);
        $this->assertStringNotContainsString('Cosecha', json_encode($line->getAttributes()) ?: '');

        // The legal register prints the lote number and never the (renamable) name.
        Livewire::test(RegistroDispensacion::class)->assertSee('B-AAA111')->assertDontSee('Cosecha verano 2026');
    }

    // 11 ------------------------------------------------------------------------------------------------------------

    public function test_the_recall_csv_has_a_lote_column_and_a_name_column(): void
    {
        $batch = $this->batch($this->amnesia, 'Cosecha verano 2026', $this->centro, 'B-AAA111');

        $columns = collect((new BatchRecall($batch))->table()->columns)->pluck('key')->all();

        $this->assertContains('lote', $columns);
        $this->assertContains('nombre', $columns);
    }
}
