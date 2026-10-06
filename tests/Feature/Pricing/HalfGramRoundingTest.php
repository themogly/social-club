<?php

namespace Tests\Feature\Pricing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\RefundDispensation;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ChargeRounding;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 355 — Ben and the club (Arron): "All the prices get rounded to the nearest 0.5 g. If you sell 0.2 g they pay for
 * half a gram; if you sell 1.1 g they pay for 1 g … If you type in the exact amount, the stock check should be bang on."
 * Two weights per line: WEIGHED (stock, limits, register) and CHARGED (price only, eighths included). Ben's answers:
 * nearest 0.5 g, minimum 0.5 g, an exact half rounds DOWN. All at €10/g with a €32 eighth unless stated.
 */
class HalfGramRoundingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Member $member;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->sede->id);
        $this->owner = $this->person(Role::OWNER);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'product_type' => ProductType::FLOWER]);
        $this->batch = $this->batchOf($this->genetic, 1000, 3200);
    }

    private function person(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->sede->id]);

        return $user;
    }

    private function batchOf(Genetic $genetic, int $perGram, ?int $eighth, int $remaining = 100000, string $acquired = '2026-09-01'): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => $remaining, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => $perGram, 'price_per_eighth_cents' => $eighth,
            'expires_on' => now()->addYear(), 'acquired_or_harvested_on' => $acquired]);
    }

    /** @param  list<array{0: Genetic, 1: int}>  $lines */
    private function commit(array $lines, bool $rounding = true, ?Member $member = null): Dispensation
    {
        return (new CommitDispensation)->handle($member ?? $this->member, $this->sede,
            array_map(fn (array $l): array => ['genetic_id' => $l[0]->id, 'batch_id' => null, 'grams_cg' => $l[1]], $lines),
            ['operator_id' => $this->owner->id, 'charge_rounding' => $rounding]);
    }

    // --- 1. The rule ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{int, int}> */
    public static function rule(): array
    {
        return ['0.20' => [20, 50], '0.50' => [50, 50], '0.70' => [70, 50], '0.75 (half → down)' => [75, 50], '0.80' => [80, 100],
            '1.10' => [110, 100], '1.25 (half → down)' => [125, 100], '1.30' => [130, 150]];
    }

    #[DataProvider('rule')]
    public function test_the_rule(int $weighed, int $charged): void
    {
        $this->assertSame($charged, ChargeRounding::charged($weighed));
    }

    // --- 2, 3, 6. Prices ------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{int, bool, int}> weighed cg, rounding on, total cents */
    public static function prices(): array
    {
        return [
            '1.10 → 1.0' => [110, true, 1000], '0.20 → 0.5' => [20, true, 500],
            '3.40 → an eighth' => [340, true, 3200], '3.60 → an eighth' => [360, true, 3200], '3.20 → 3.0 per gram' => [320, true, 3000],
            '4.10 → an eighth + 0.5 g' => [410, true, 3700], '6.90 → two eighths' => [690, true, 6400],
            'off: 1.10' => [110, false, 1100], 'off: 3.40 per gram' => [340, false, 3400], 'off: 3.60 eighth + 0.10 g' => [360, false, 3300],
        ];
    }

    #[DataProvider('prices')]
    public function test_the_price_and_the_counter_shows_what_is_committed(int $weighed, bool $rounding, int $total): void
    {
        // 11 — preview equals commit: the counter's total, before, is the committed total.
        $pos = $this->counter($rounding)->call('chooseGenetic', $this->genetic->id)->set('weightInput', number_format($weighed / 100, 2))->call('addLine');
        $pos->assertSee(__('Registrar aportación · :total', ['total' => Money::fromCents($total)->formatted()]));

        $d = $this->commit([[$this->genetic, $weighed]], $rounding);
        $this->assertSame($total, $d->total_cents->cents);
        $this->assertSame($weighed, (int) $d->lines()->sum('grams_cg'), 'the WEIGHED grams are what was dispensed');
        $this->assertSame($rounding ? ChargeRounding::charged($weighed) : $weighed, (int) $d->lines()->sum('charged_cg'));
        $this->assertSame($rounding, (bool) $d->charge_rounding);
    }

    // --- 4–5. The eighth group and the floor -------------------------------------------------------------------------------------

    public function test_two_lines_sharing_an_eighth_are_rounded_each_on_its_own_and_grouped_on_charged_grams(): void
    {
        $other = Genetic::factory()->create(['organisation_id' => $this->org->id, 'product_type' => ProductType::FLOWER]);
        $this->batchOf($other, 1000, 3200);

        $d = $this->commit([[$this->genetic, 120], [$other, 230]]); // 1.0 + 2.5 = 3.5 → one eighth

        $this->assertSame(3200, $d->total_cents->cents);
        $byGenetic = $d->lines()->get()->keyBy('genetic_id');
        $this->assertSame([100, 250], [$byGenetic[$this->genetic->id]->charged_cg->centigrams, $byGenetic[$other->id]->charged_cg->centigrams]);
        $this->assertSame([914, 2286], [$byGenetic[$this->genetic->id]->line_total_cents->cents, $byGenetic[$other->id]->line_total_cents->cents], 'split 1.0 : 2.5, no lost cent');
    }

    public function test_the_floor_still_holds_on_charged_grams(): void
    {
        $dear = Genetic::factory()->create(['organisation_id' => $this->org->id, 'product_type' => ProductType::FLOWER]);
        $this->batchOf($dear, 1000, 3600); // an eighth dearer than 3.5 × €10

        $this->assertSame(3500, $this->commit([[$dear, 340]])->total_cents->cents, 'per gram on 3.5 g, never more');
    }

    // --- 8–10. Stock and limits are exact; a line across two batches -----------------------------------------------------------

    public function test_stock_and_limits_read_the_weighed_grams(): void
    {
        $d = $this->commit([[$this->genetic, 110]]);

        $this->assertSame(100000 - 110, $this->batch->fresh()->remaining_cg->centigrams);
        $this->assertSame(110, $d->dispensedGramsCg());
        // The receipt shows both: what was weighed, and what was charged.
        $this->get(route('counter.pos.receipt', $d))->assertOk()->assertSee('1.10 g')->assertSee(__('se cobra :grams', ['grams' => '1.00 g']));
    }

    public function test_a_line_across_two_batches_charges_the_rounded_grams_and_moves_the_weighed_ones(): void
    {
        $this->batch->forceFill(['status' => BatchStatus::CLOSED])->save(); // only the two batches below
        $old = $this->batchOf($this->genetic, 1000, null, 70, '2026-08-01');
        $new = $this->batchOf($this->genetic, 1200, null, 10000, '2026-09-01');

        $d = $this->commit([[$this->genetic, 110]]); // weighed 0.70 + 0.40 → charged 1.00: 0.70 @ 10 + 0.30 @ 12
        $lines = $d->lines()->get()->keyBy('batch_id');

        $this->assertSame([70, 40], [$lines[$old->id]->grams_cg->centigrams, $lines[$new->id]->grams_cg->centigrams]);
        $this->assertSame([70, 30], [$lines[$old->id]->charged_cg->centigrams, $lines[$new->id]->charged_cg->centigrams]);
        $this->assertSame(700 + 360, $d->total_cents->cents);
        $this->assertSame([0, 10000 - 40], [$old->fresh()->remaining_cg->centigrams, $new->fresh()->remaining_cg->centigrams]);
    }

    public function test_a_line_across_two_batches_with_the_eighth(): void
    {
        $this->batch->forceFill(['status' => BatchStatus::CLOSED])->save(); // only the two batches below
        $old = $this->batchOf($this->genetic, 1000, 3200, 200, '2026-08-01');
        $new = $this->batchOf($this->genetic, 1000, 3200, 10000, '2026-09-01');

        $d = $this->commit([[$this->genetic, 340]]); // 2.00 + 1.40 weighed → 2.00 + 1.50 charged → one eighth
        $lines = $d->lines()->get()->keyBy('batch_id');

        $this->assertSame(3200, $d->total_cents->cents);
        $this->assertSame([200, 150], [$lines[$old->id]->charged_cg->centigrams, $lines[$new->id]->charged_cg->centigrams]);
        $this->assertSame([200, 140], [$lines[$old->id]->grams_cg->centigrams, $lines[$new->id]->grams_cg->centigrams]);
    }

    // --- 12–14. History, refunds, units -------------------------------------------------------------------------------------------

    public function test_history_is_stable_when_the_setting_changes(): void
    {
        $d = $this->commit([[$this->genetic, 110]]);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL, (string) $this->sede->id);

        $fresh = $d->fresh();
        $this->assertSame([1000, 100, true], [$fresh->total_cents->cents, (int) $fresh->lines()->sum('charged_cg'), (bool) $fresh->charge_rounding]);
    }

    public function test_a_refund_is_capped_at_what_was_charged_and_what_was_weighed(): void
    {
        $d = $this->commit([[$this->genetic, 110]]); // €10 charged, 1.10 g weighed

        (new RefundDispensation)->handle($d, $this->owner, ['amount_cents' => 1000, 'grams_cg' => 110, 'reason' => 'Prueba',
            'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET]);
        $this->expectException(RuntimeException::class);
        (new RefundDispensation)->handle($d->fresh(), $this->owner, ['amount_cents' => 1, 'reason' => 'Prueba',
            'destination' => RefundDestination::STOCK, 'method' => RefundMethod::WALLET]);
    }

    public function test_unit_products_are_unaffected(): void
    {
        $preroll = Genetic::factory()->create(['organisation_id' => $this->org->id, 'product_type' => ProductType::PREROLL, 'grams_per_unit_cg' => 70]);
        Batch::factory()->units(10)->create(['organisation_id' => $this->org->id, 'genetic_id' => $preroll->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'price_per_unit_cents' => 500, 'expires_on' => now()->addYear()]);

        $d = (new CommitDispensation)->handle($this->member, $this->sede, [['genetic_id' => $preroll->id, 'batch_id' => null, 'grams_cg' => 140, 'units' => 2]],
            ['operator_id' => $this->owner->id, 'charge_rounding' => true]);

        $this->assertSame(1000, $d->total_cents->cents);
        $this->assertNull($d->lines()->first()->charged_cg);
    }

    // --- 7. The switch, per staff session ----------------------------------------------------------------------------------------

    private function counter(?bool $rounding = null, ?User $operator = null): Testable
    {
        $operator ??= $this->owner;
        session(['counter.location_id' => $this->sede->id]);
        if (! TillSession::query()->where('status', 'OPEN')->exists()) {
            (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        }
        $this->actingAs($operator);
        CounterOperator::set($operator);
        if ($rounding !== null && ChargeRounding::enabled($operator, (string) $this->sede->id) !== $rounding) {
            ChargeRounding::set($operator, $this->sede, $rounding);
        }

        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id);
    }

    public function test_the_switch_defaults_from_the_sede_reprices_the_basket_and_is_audited(): void
    {
        $pos = $this->counter()->call('chooseGenetic', $this->genetic->id)->set('weightInput', '1.10')->call('addLine')
            ->assertSeeHtml('data-charge-rounding="on"')->assertDontSeeHtml('data-charge-rounding-changed')
            ->assertSee(__('se cobra :grams', ['grams' => '1.00 g']))
            ->assertSee(__('Registrar aportación · :total', ['total' => Money::fromCents(1000)->formatted()]));

        $pos->call('toggleChargeRounding')->assertSeeHtml('data-charge-rounding="off"')->assertSeeHtml('data-charge-rounding-changed')
            ->assertDontSee(__('se cobra :grams', ['grams' => '1.00 g']))
            ->assertSee(__('Registrar aportación · :total', ['total' => Money::fromCents(1100)->formatted()]));
        $this->assertSame(1, AuditLog::query()->where('action', 'counter.rounding.toggled')->count());

        // The commit reads the server's flag: what the counter showed is what is charged.
        $pos->call('quickCash')->call('commitDispensation');
        $this->assertSame(1100, Dispensation::query()->withoutGlobalScopes()->sole()->total_cents->cents);
    }

    public function test_the_switch_is_kept_for_this_person_and_sede_only(): void
    {
        $staff = $this->person(Role::STAFF);
        $line = fn (Testable $pos): Testable => $pos->call('chooseGenetic', $this->genetic->id)->set('weightInput', '1.10')->call('addLine');
        $line($this->counter(operator: $staff))->call('toggleChargeRounding'); // off, for staff
        $this->assertFalse(ChargeRounding::enabled($staff, (string) $this->sede->id));

        // Two visits in a row: both priced unrounded (1.10 g → €11).
        foreach ([1, 2] as $visit) {
            $line($this->counter(operator: $staff))->assertSeeHtml('data-charge-rounding="off"')->call('quickCash')->call('commitDispensation');
        }
        $this->assertSame([1100, 1100], Dispensation::query()->withoutGlobalScopes()->orderBy('created_at')->get()->map(fn (Dispensation $d): int => $d->total_cents->cents)->all());

        // A lock and the same PIN again: still off.
        CounterOperator::clear();
        $line($this->counter(operator: $staff))->assertSeeHtml('data-charge-rounding="off"');

        // Someone else starts from the sede's default…
        $line($this->counter(operator: $this->person(Role::MANAGER)))->assertSeeHtml('data-charge-rounding="on"');

        // …and another sede has its own.
        $north = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->assertTrue(ChargeRounding::enabled($staff, (string) $north->id));
    }
}
