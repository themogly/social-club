<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Enums\UnitType;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\StockTakeLine;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 91 — a staff day at the counter. The blocker (a till that cannot be closed if one jar cannot be
 * weighed) and the frictions (forms that gate too late, a basket that drops into dead space, a payment
 * apparatus shown before there is anything to pay for, a lookup split across two boxes, and a reweigh whose
 * copy lies about its filter). Presentation + one missing state; the ledgers behave exactly as before.
 */
class CounterStaffDayTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    private function flowerBatch(int $initialCg, int $remainingCg): Batch
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => $initialCg, 'remaining_cg' => $remainingCg, 'status' => BatchStatus::OPEN,
        ]);
    }

    // 1) The blocker — a jar that cannot be weighed no longer stops the close.
    public function test_a_batch_marked_not_counted_closes_the_reweigh_and_leaves_its_stock_untouched(): void
    {
        $this->actingAs($this->operator());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000); // touched; expected 900 g

        Livewire::test(TillSession::class)
            ->set('reweighNotCounted', [$batch->id => true])
            ->set('reweighReasons', [$batch->id => 'Bote no localizado'])
            ->call('submitReweigh')
            ->assertSet('reweighDone', true); // the close proceeds

        // Stock UNTOUCHED — no adjustment, no merma.
        $this->assertSame(90000, $batch->refresh()->remaining_cg->centigrams);
        $this->assertSame(0, StockMovement::query()->where('type', 'ADJUSTMENT')->count());
        $this->assertSame(0, StockMovement::query()->where('type', 'MERMA')->count());

        // The omission is recorded and visible, with its reason.
        $line = StockTakeLine::query()->where('countable_id', $batch->id)->firstOrFail();
        $this->assertTrue($line->not_counted);
        $this->assertSame('Bote no localizado', $line->not_counted_reason);
    }

    public function test_a_not_counted_batch_needs_a_reason(): void
    {
        $this->actingAs($this->operator());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000);

        Livewire::test(TillSession::class)
            ->set('reweighNotCounted', [$batch->id => true])
            ->set('reweighReasons', [$batch->id => '   '])
            ->call('submitReweigh')
            ->assertSet('reweighDone', false) // refused
            ->assertSet('flashType', 'error');
    }

    // 2) A real count of zero is NOT "not counted".
    public function test_zero_is_a_real_count_distinct_from_not_counted(): void
    {
        $this->actingAs($this->operator());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $zeroed = $this->flowerBatch(100000, 90000);   // will be counted as 0 → drains to 0
        $skipped = $this->flowerBatch(100000, 90000);  // not counted → untouched

        Livewire::test(TillSession::class)
            ->set('reweighCounts', [$zeroed->id => '0'])
            ->set('reweighNotCounted', [$skipped->id => true])
            ->set('reweighReasons', [$skipped->id => 'En uso'])
            ->call('submitReweigh')
            ->assertSet('reweighDone', true);

        // Count of zero: a real variance and an adjustment down to 0.
        $this->assertSame(0, $zeroed->refresh()->remaining_cg->centigrams);
        $this->assertSame(1, StockMovement::query()->where('type', 'ADJUSTMENT')->count());
        $zeroLine = StockTakeLine::query()->where('countable_id', $zeroed->id)->firstOrFail();
        $this->assertFalse($zeroLine->not_counted);
        $this->assertSame(0, $zeroLine->counted_cg->centigrams);

        // Not counted: untouched, no adjustment.
        $this->assertSame(90000, $skipped->refresh()->remaining_cg->centigrams);
        $this->assertTrue(StockTakeLine::query()->where('countable_id', $skipped->id)->firstOrFail()->not_counted);
    }

    // 3) The till forms gate BEFORE the work, not on submit.
    public function test_the_till_forms_are_gated_until_an_operator_is_identified(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        // NB: no CounterOperator::set — the operator is not identified.

        $html = Livewire::test(TillSession::class)->assertSet('noLocation', false)->html();

        $this->assertStringContainsString('data-needs-operator', $html);
        $this->assertStringContainsString(__('Identifícate como operario para registrar movimientos, gastos o cobros de cuota.'), $html);
        // The form controls are disabled (a fieldset), not merely refused on submit.
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $html);
    }

    public function test_the_till_forms_are_ungated_once_an_operator_is_identified(): void
    {
        $this->actingAs($this->operator()); // CounterOperator::set
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)->html();
        $this->assertStringNotContainsString('data-needs-operator', $html);
    }

    /**
     * Prompt 175 put the preconditions on full-screen blocking states, so the POS layout only exists once the
     * screen is usable — a till open and a socio identified. These assertions are about that screen.
     */
    private function usableDispensary(): Testable
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        return Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
    }

    // 4) The dispensary basket is pinned to its own column at 1024 (the batch-2 bar POS fix, now shared).
    public function test_the_dispensary_basket_is_pinned_to_its_own_column_at_the_tablet_width(): void
    {
        $this->actingAs($this->operator());
        $html = $this->usableDispensary()->html();

        $this->assertStringContainsString('lg:grid-cols-[minmax(0,1fr)_22rem]', $html);
        $this->assertStringContainsString('lg:col-start-2 lg:row-start-1 lg:row-span-2', $html);
    }

    // 5) The payment apparatus is disclosed only once the basket has a line.
    public function test_the_payment_apparatus_is_hidden_until_the_basket_has_a_line(): void
    {
        $this->actingAs($this->operator());
        $html = $this->usableDispensary()->html();

        // Empty basket → the next-step hint, not the tender/signature apparatus.
        $this->assertStringContainsString('data-empty-basket-hint', $html);
        $this->assertStringNotContainsString('Efectivo entregado (€)', $html);
        // …but the charge button stays observable (prompt 60).
        $this->assertStringContainsString('x-bind:disabled="! online"', $html);
    }

    // 6) A name typed into the scan field routes to the SAME member search.
    public function test_a_name_typed_into_the_scan_field_routes_to_the_member_search(): void
    {
        $this->actingAs($this->operator());
        // Prompt 175: the member step's blocking state carries the lookup itself, so the search is reachable
        // — but only once the till step above it in the chain is met.
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Lucía', 'last_name' => 'García', 'member_no' => 'M-00099',
        ]);

        Livewire::test(DispensaryPos::class)
            ->set('scan', 'García')
            ->call('submitScan')
            ->assertSet('search', 'García') // routed to the search
            ->assertSee('García');          // and the member surfaces
    }

    // 7) The reweigh copy matches the filter, and the progress reflects the count.
    public function test_the_reweigh_copy_matches_the_filter_and_progress_reflects_the_count(): void
    {
        app()->setLocale('es'); // assert the Spanish copy verbatim (no HTML-escaped apostrophe as in en)
        $this->actingAs($this->operator());
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $batch = $this->flowerBatch(100000, 90000);

        $component = Livewire::test(TillSession::class)->set('reweighing', true);
        $html = $component->html();

        // Copy describes the real filter (touched since intake), not "dispensed today".
        $this->assertStringContainsString(__('Pesa cada lote de flor tocado desde su entrada e introduce los gramos contados. Si no puedes contar un bote, márcalo como no contado e indica el motivo — su stock no se tocará. El peso esperado se revela solo después de confirmar (recuento a ciegas).'), $html);
        $this->assertStringNotContainsString('dispensado hoy', $html);

        // Progress starts at 0 of 1…
        $this->assertStringContainsString(__(':done de :total pesados', ['done' => 0, 'total' => 1]), $html);
        // …and reflects a weighed batch.
        $after = $component->set('reweighCounts', [$batch->id => '90'])->html();
        $this->assertStringContainsString(__(':done de :total pesados', ['done' => 1, 'total' => 1]), $after);
    }

    // 8) The seed leaves an untouched open WEIGHT batch, absent from the reweigh.
    public function test_a_seeded_database_leaves_an_untouched_weight_batch_out_of_the_reweigh(): void
    {
        Notification::fake();
        $this->app['env'] = 'local';
        $this->travelTo(CarbonImmutable::parse('2026-07-20 12:00:00'));
        $this->seed(DatabaseSeeder::class);

        // An OPEN, WEIGHT, never-dispensed batch exists (remaining = initial).
        $untouched = Batch::query()->withoutGlobalScopes()
            ->where('status', BatchStatus::OPEN->value)
            ->whereColumn('remaining_cg', '=', 'initial_cg')
            ->whereHas('genetic', fn ($q) => $q->where('unit_type', UnitType::WEIGHT->value))
            ->first();

        $this->assertNotNull($untouched, 'The seed must leave at least one untouched open weight batch.');

        // …and the EOD reweigh for its sede excludes it (its filter is "touched since intake").
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$untouched->location_id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($untouched->location_id);
        CounterOperator::set($user);

        $ids = Livewire::test(TillSession::class)->instance()->reweighBatches()->pluck('id')->all();
        $this->assertNotContains($untouched->id, $ids);
    }
}
