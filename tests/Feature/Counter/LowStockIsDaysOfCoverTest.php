<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
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
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\StockCover;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 216 — "low stock" measured against demand: days of cover, not a flat figure.
 *
 * **Two wrong defaults, facing opposite ways.** 54's flat 50 g badged all six seeded genetics at once —
 * always on, so furniture. 213 replaced it with one member's daily allowance (350 cg), and on the same
 * holdings (12.95 g – 32.66 g) **nothing badges at all**: a genetic only badges once it can no longer fill a
 * single full order, at which point it is not low, it is gone. Always-on and fires-too-late are the same
 * failure wearing different clothes.
 *
 * **The base was the problem, not the multiple.** An allowance measures what members MAY take; consumption
 * measures what they DO. Any flat threshold overstates urgency for a slow mover and understates it for the
 * popular genetic — which is precisely the one that runs out. This resolves 213's
 * `OVERNIGHT-DEFAULT — CONFIRM`: the frame is cover, not quantity.
 */
class LowStockIsDaysOfCoverTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 50]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);

        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subYear(), 'expires_at' => now()->addYear(), 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function genetic(string $name, int $onHandCg): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name, 'active' => true]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true, 'low_stock_threshold_cg' => null,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => max($onHandCg, 1), 'remaining_cg' => $onHandCg,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic->fresh();
    }

    /**
     * An anchor sale well BEFORE the window: the genetic has been selling here a while, so it is not thin.
     *
     * It contributes nothing to the rate — the window excludes it — which is exactly what makes it the right
     * way to say "this genetic has history" without saying anything about its demand.
     */
    private function established(Genetic $genetic): void
    {
        $this->dispensed($genetic, 1, 60);
    }

    /**
     * Record a dispensation of this genetic, `$daysAgo` ago.
     *
     * Written straight to the ledger rather than through `CommitDispensation` — this seeds HISTORY the club
     * already has (a fortnight of past trading), and the compliance boundary would gate on limits and stock
     * it has no business enforcing on the past. Every column the rate reads is populated.
     */
    private function dispensed(Genetic $genetic, int $cg, int $daysAgo, DispensationStatus $status = DispensationStatus::COMPLETED): void
    {
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'member_id' => $this->member()->id,
            'status' => $status,
            'dispensed_at' => now()->subDays($daysAgo),
        ]);

        DispensationLine::factory()->create([
            'dispensation_id' => $dispensation->id,
            'genetic_id' => $genetic->id,
            'grams_cg' => $cg,
        ]);
    }

    // --- The finding ---------------------------------------------------------------------------

    /**
     * **Two genetics, identical on-hand, different demand — they diverge.**
     *
     * Fails against `main`, where a flat threshold makes them behave identically. That is the whole finding:
     * a flat figure cannot tell the popular genetic from the slow one, and the popular one is what runs out.
     */
    public function test_identical_stock_with_different_demand_diverges(): void
    {
        $this->operator();

        $fast = $this->genetic('Fast mover', 2000);   // 20 g
        $slow = $this->genetic('Slow mover', 2000);   // 20 g, same

        // Selling here for months, so neither is thin — then a full window of trading at very different rates.
        $this->established($fast);
        $this->established($slow);

        for ($day = 1; $day <= 14; $day++) {
            $this->dispensed($fast, 1500, $day);      // 15 g/day → ~1.3 days of cover
            $this->dispensed($slow, 50, $day);        // 0.5 g/day → ~40 days
        }

        $fastVerdict = StockCover::verdict($fast, $this->location->id, 2000);
        $slowVerdict = StockCover::verdict($slow, $this->location->id, 2000);

        $this->assertTrue($fastVerdict['low'], 'the fast mover does not badge');
        $this->assertFalse($slowVerdict['low'], 'the slow mover badges anyway');
        $this->assertSame('cover', $fastVerdict['basis']);
        $this->assertSame('cover', $slowVerdict['basis']);

        // …and on the screen, the fast one carries its FIGURE rather than the word.
        $html = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)->html();
        $this->assertStringContainsString(StockCover::label($fastVerdict['days']), $html);
    }

    /** The figure is right — asserted against the resolver's own arithmetic, not a literal. */
    public function test_the_cover_figure_is_the_arithmetic(): void
    {
        $this->operator();
        $genetic = $this->genetic('Amnesia Haze', 2800);   // 28 g on hand
        $this->established($genetic);

        for ($day = 1; $day <= 14; $day++) {
            $this->dispensed($genetic, 200, $day);          // 2 g/day
        }

        $window = StockCover::windowDays($this->location->id);
        $trailing = StockCover::trailingCgFor([$genetic->id], $this->location->id)[$genetic->id];

        $this->assertSame(14 * 200, $trailing, 'the trailing sum is wrong');
        $this->assertEqualsWithDelta(
            StockCover::days(2800, $trailing, $window),
            StockCover::verdict($genetic, $this->location->id, 2800)['days'],
            0.001,
            'the verdict and the arithmetic disagree',
        );

        // 28 g at 2 g/day is 14 days — comfortably above the two-day line.
        $this->assertEqualsWithDelta(14.0, (float) StockCover::verdict($genetic, $this->location->id, 2800)['days'], 0.001);
        $this->assertFalse(StockCover::verdict($genetic, $this->location->id, 2800)['low']);
    }

    /** A voided dispensation did not happen, so it does not set the rate (177's rule, on this side). */
    public function test_voided_dispensations_do_not_count_toward_the_rate(): void
    {
        $this->operator();
        $genetic = $this->genetic('Critical Kush', 2000);
        $this->established($genetic);

        for ($day = 1; $day <= 14; $day++) {
            $this->dispensed($genetic, 100, $day);                                     // real
            $this->dispensed($genetic, 5000, $day, DispensationStatus::VOIDED);        // undone
        }

        $this->assertSame(1400, StockCover::trailingCgFor([$genetic->id], $this->location->id)[$genetic->id]);
        $this->assertFalse(
            StockCover::verdict($genetic, $this->location->id, 2000)['low'],
            'a voided dispensation dragged the cover figure down',
        );
    }

    // --- The fallbacks, each a decision ---------------------------------------------------------

    /** Thin history — never dispensed here — falls back to 213's allowance figure, not to "infinite cover". */
    public function test_a_genetic_never_dispensed_here_falls_back_to_the_allowance(): void
    {
        $this->operator();
        $daily = (int) Settings::get('daily_limit_cg', 300);

        $plenty = $this->genetic('Nueva', $daily * 10);
        $scarce = $this->genetic('Nueva y casi vacía', max(1, $daily - 1));

        $this->assertSame('thin-history', StockCover::verdict($plenty, $this->location->id, $daily * 10)['basis']);
        $this->assertFalse(StockCover::verdict($plenty, $this->location->id, $daily * 10)['low']);
        $this->assertTrue(
            StockCover::verdict($scarce, $this->location->id, max(1, $daily - 1))['low'],
            'a brand-new genetic with almost nothing left read as covered',
        );
    }

    /** First sold INSIDE the window is thin too — the rate would be an artefact of when it arrived. */
    public function test_a_genetic_first_sold_inside_the_window_is_thin(): void
    {
        $this->operator();
        $genetic = $this->genetic('Recién llegada', 2000);

        $this->dispensed($genetic, 1500, 2);
        $this->dispensed($genetic, 1500, 1);

        $this->assertSame('thin-history', StockCover::verdict($genetic, $this->location->id, 2000)['basis']);
        $this->assertNull(StockCover::verdict($genetic, $this->location->id, 2000)['days'], 'a thin rate was published as a figure');
    }

    /**
     * Zero trailing consumption with stock on hand: **no badge**, deliberately.
     *
     * Nothing is running out; it is not moving. That may well be its own problem, but it is not this badge's
     * problem — and painting it here would put the badge back on permanently for every slow mover, which is
     * exactly the failure this branch exists to end.
     */
    public function test_a_genetic_that_is_not_moving_does_not_badge(): void
    {
        $this->operator();
        $genetic = $this->genetic('Parada', 500);

        // History exists, but all of it is older than the window.
        $this->dispensed($genetic, 1000, 60);

        $verdict = StockCover::verdict($genetic, $this->location->id, 500);

        $this->assertSame('not-moving', $verdict['basis']);
        $this->assertFalse($verdict['low']);
        $this->assertNull($verdict['days']);
    }

    /** The zero-rate division is guarded before it divides — never a throw, never "∞ días". */
    public function test_the_zero_rate_division_cannot_throw_or_render_infinity(): void
    {
        $this->assertNull(StockCover::days(5000, 0, 14));
        $this->assertNull(StockCover::days(5000, 100, 0));
        $this->assertNull(StockCover::label(null));
        $this->assertStringNotContainsString('∞', (string) StockCover::label(StockCover::days(5000, 100, 14)));
    }

    /** Explicit overrides keep their precedence as absolute floors — per-sede first, then the org setting. */
    public function test_explicit_overrides_still_win_in_the_existing_precedence(): void
    {
        $this->operator();
        $genetic = $this->genetic('Con umbral', 2000);
        $this->established($genetic);
        for ($day = 1; $day <= 14; $day++) {
            $this->dispensed($genetic, 10, $day);   // barely moving → cover would say "fine"
        }

        $this->assertFalse(StockCover::verdict($genetic, $this->location->id, 2000)['low']);

        // The ORG setting states a figure — it wins over the derivation.
        Settings::set('low_stock_threshold_cg', 5000, SettingType::INT);
        $orgVerdict = StockCover::verdict($genetic->fresh(), $this->location->id, 2000);
        $this->assertSame('explicit', $orgVerdict['basis']);
        $this->assertTrue($orgVerdict['low']);

        // …and the PER-SEDE figure wins over the org one.
        $genetic->prices()->withoutGlobalScopes()
            ->where('location_id', $this->location->id)->whereNull('tier_id')
            ->update(['low_stock_threshold_cg' => 100]);

        $sedeVerdict = StockCover::verdict($genetic->fresh(), $this->location->id, 2000);
        $this->assertSame('explicit', $sedeVerdict['basis']);
        $this->assertFalse($sedeVerdict['low'], 'the per-sede figure did not win');
    }

    /** Both thresholds are settings, per sede, with the recorded defaults. */
    public function test_the_window_and_the_day_threshold_are_settings(): void
    {
        $this->operator();

        $this->assertSame(14, StockCover::windowDays($this->location->id));
        $this->assertSame(2, StockCover::lowDays($this->location->id));

        Settings::set('stock_cover_window_days', 7, SettingType::INT);
        Settings::set('stock_cover_low_days', 5, SettingType::INT);

        $this->assertSame(7, StockCover::windowDays($this->location->id));
        $this->assertSame(5, StockCover::lowDays($this->location->id));
    }

    // --- 185's boundary, re-asserted -------------------------------------------------------------

    /**
     * **The member-facing output contains no number.** The LOW band changed MEANING, not shape.
     *
     * Asserted against the raw response body for every figure this branch introduced — the cover days, the
     * window, the rate and the trailing sum — because the temptation to pass the useful new number through
     * to the member is exactly the mistake 185 exists to prevent.
     */
    public function test_the_member_menu_carries_the_state_and_no_number(): void
    {
        $this->operator();
        $genetic = $this->genetic('Se acaba', 2000);
        $this->established($genetic);
        for ($day = 1; $day <= 14; $day++) {
            $this->dispensed($genetic, 1500, $day);
        }

        $member = $this->member();
        $this->assertSame(Genetic::LOW, $genetic->fresh()->availabilityAt($this->location->id));

        $verdict = StockCover::verdict($genetic->fresh(), $this->location->id, 2000);
        $html = (string) $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->getContent();

        $this->assertStringContainsString('data-availability="low"', $html, 'the state did not reach the member');
        $this->assertStringContainsString(__('Quedan pocas'), $html);

        // Asserted against the RENDERED FIGURES this branch introduced, not against bare digits — an HTML
        // page is full of digits, and an assertion that fails on "1" proves nothing. The leak vectors are
        // the cover label, the trailing sum and the words that would carry a rate.
        foreach ([
            (string) StockCover::label($verdict['days']),
            (string) StockCover::trailingCgFor([$genetic->id], $this->location->id)[$genetic->id],
            'días de stock', 'stock_cover', 'trailing',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "the member menu leaked '{$leak}'");
        }

        // …and the state word is ALL that carries the meaning: no `≈` anywhere on the page.
        $this->assertStringNotContainsString('≈', $html, 'a cover figure reached the member menu');
    }

    // --- The grid must not gain an N+1 -----------------------------------------------------------

    /**
     * **This branch's queries do not scale with the catalogue.**
     *
     * Scoped deliberately, and the scope is the honest part. The grid was ALREADY per-genetic before this
     * branch — `ResolvePrice`, `SelectBatch::fefo()` and the remaining-stock read all run per card, and that
     * predates 216 by a long way. Asserting "the whole grid is flat" would fail on `main` too, and would be
     * this branch claiming a fix it did not make.
     *
     * What 216 owes is that **trailing consumption for the whole grid is ONE grouped query, not one per
     * card** — so that is what is measured: the queries touching `dispensation_lines` are counted at 4
     * genetics and again at 24, and must be identical. Measured: **2** (the trailing sum, and the
     * first-sold-here date behind the thin-history rule), at both sizes.
     *
     * The pre-existing per-card cost is recorded in DECISIONS.md rather than quietly absorbed here.
     */
    public function test_the_cover_queries_do_not_scale_with_the_catalogue(): void
    {
        $this->operator();
        $member = $this->member();

        $seed = function (int $from, int $to): void {
            for ($i = $from; $i <= $to; $i++) {
                $g = $this->genetic('Genética '.$i, 5000);
                $this->established($g);
                $this->dispensed($g, 100, 3);
            }
        };

        $coverQueries = function () use ($member): int {
            // Warm first: `disableQueryLog()` stops recording but does not flush, and a first render also
            // pays for cold caches — both of which produced false readings in prompt 205's own N+1 test.
            Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            // The two COVER queries specifically, by their own signatures — the screen touches
            // `dispensation_lines` for other reasons too ("Su habitual" reads the member's own history),
            // and counting those would measure somebody else's work.
            return count(array_filter($log, function (array $q): bool {
                $sql = (string) $q['query'];

                return str_contains($sql, 'sum(dispensation_lines.grams_cg)')
                    || str_contains($sql, 'SUM(dispensation_lines.grams_cg)')
                    || str_contains($sql, 'min(dispensations.dispensed_at)')
                    || str_contains($sql, 'MIN(dispensations.dispensed_at)');
            }));
        };

        $seed(1, 4);
        $small = $coverQueries();

        $seed(5, 24);
        $large = $coverQueries();

        // The property that matters is that it does not SCALE. The absolute number is 4 rather than 2
        // because the screen builds its rows twice per render — once for the grid and once for "Su habitual",
        // which predates this branch; 216 owes two grouped queries per build, and delivers them.
        $this->assertSame(4, $small, "the cover figures are not the grouped queries: {$small}");
        $this->assertSame($small, $large, "cover queries scaled with the catalogue: {$small} became {$large}");
    }
}
