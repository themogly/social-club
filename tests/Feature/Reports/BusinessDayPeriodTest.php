<?php

namespace Tests\Feature\Reports;

use App\Models\Location;
use App\Models\Organisation;
use App\Support\BusinessDay;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 105 — reports must define "a day" the way the gram cap and the Z-report do (BusinessDay), not a
 * naive UTC calendar day. For a Madrid/06:00 club that is 4–5 h apart, so a member at the cap can read as
 * over or under depending on which document is opened. Storage stays UTC; only how a Period is DERIVED changes.
 */
class BusinessDayPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function location(string $tz = 'Europe/Madrid', string $cutoff = '06:00'): Location
    {
        $org = Organisation::factory()->create();

        return Location::factory()->create(['organisation_id' => $org->id, 'timezone' => $tz, 'business_day_cutoff' => $cutoff]);
    }

    public function test_the_report_day_is_the_business_day_not_the_utc_calendar_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC')); // summer (CEST)
        $location = $this->location();

        // The report's "today" resolves to EXACTLY the business-day window the gram cap uses.
        $period = Period::fromKey('today', $location);
        [$capStart, $capEnd] = BusinessDay::window($location);

        $this->assertTrue($period->start->equalTo($capStart), 'report day start must equal the cap day start');
        $this->assertTrue($period->end->equalTo($capEnd));
        // …and that is NOT UTC midnight (a Madrid 06:00 cutoff is 04:00 UTC in summer).
        $this->assertNotSame('00:00:00', $period->start->setTimezone('UTC')->format('H:i:s'));
    }

    public function test_without_a_location_it_stays_the_naive_calendar_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'));

        $period = Period::fromKey('today'); // legacy, location-agnostic
        $this->assertSame('00:00:00', $period->start->format('H:i:s'));
        $this->assertSame('day', $period->type);
    }

    public function test_an_early_hours_instant_belongs_to_the_previous_business_day(): void
    {
        // 03:00 Madrid is BEFORE the 06:00 cutoff, so it is the tail of the night that is ending.
        $at = CarbonImmutable::parse('2026-07-15 03:00:00', 'Europe/Madrid');
        $location = $this->location();

        $window = Period::businessWindow($location, 'day', $at);
        $this->assertTrue($window->start->lessThanOrEqualTo($at) && $window->end->greaterThan($at));
        // The window is the 14th's business day (06:00 14th → 06:00 15th), not the 15th's.
        $this->assertSame('2026-07-14', $window->start->setTimezone('Europe/Madrid')->toDateString());
    }

    public function test_a_non_default_cutoff_shifts_the_window(): void
    {
        $at = CarbonImmutable::parse('2026-07-15 05:00:00', 'Europe/Madrid'); // between 04:00 and 06:00
        $sixAm = Period::businessWindow($this->location('Europe/Madrid', '06:00'), 'day', $at);
        $fourAm = Period::businessWindow($this->location('Europe/Madrid', '04:00'), 'day', $at);

        // At 05:00: the 06:00-cutoff club is still on the PREVIOUS day; the 04:00-cutoff club is on TODAY.
        $this->assertSame('2026-07-14', $sixAm->start->setTimezone('Europe/Madrid')->toDateString());
        $this->assertSame('2026-07-15', $fourAm->start->setTimezone('Europe/Madrid')->toDateString());
    }

    public function test_a_different_timezone_reports_on_its_own_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 04:00:00', 'UTC')); // 06:00 Madrid, 21:00 (prev) in LA
        $madrid = Period::fromKey('today', $this->location('Europe/Madrid', '00:00'));
        $la = Period::fromKey('today', $this->location('America/Los_Angeles', '00:00'));

        $this->assertNotTrue($madrid->start->equalTo($la->start)); // each on its own local day
    }

    public function test_previous_returns_the_previous_business_day_across_dst_in_both_directions(): void
    {
        $location = $this->location('Europe/Madrid', '06:00');

        // Spring forward: 29 Mar 2026, clocks 02:00→03:00. The business day 28→29 Mar contains it → 23 h.
        $this->travelTo(CarbonImmutable::parse('2026-03-29 12:00:00', 'Europe/Madrid'));
        $prevMar = Period::fromKey('today', $location)->previous();
        $this->assertSame(23 * 3600, (int) $prevMar->start->diffInSeconds($prevMar->end), 'spring-forward previous day is 23 h');

        // Fall back: 25 Oct 2026, clocks 03:00→02:00. The business day 24→25 Oct contains it → 25 h.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Europe/Madrid'));
        $prevOct = Period::fromKey('today', $location)->previous();
        $this->assertSame(25 * 3600, (int) $prevOct->start->diffInSeconds($prevOct->end), 'fall-back previous day is 25 h');
    }

    public function test_a_boundary_instant_falls_in_exactly_one_half_open_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'));
        $location = $this->location('UTC', '00:00');

        $today = Period::fromKey('today', $location);
        $yesterday = $today->previous();

        // The shared instant (yesterday.end == today.start) belongs to TODAY only — [start, end) is half-open.
        $boundary = $today->start;
        $inYesterday = $boundary->greaterThanOrEqualTo($yesterday->start) && $boundary->lessThan($yesterday->end);
        $inToday = $boundary->greaterThanOrEqualTo($today->start) && $boundary->lessThan($today->end);
        $this->assertFalse($inYesterday, 'the boundary instant must NOT fall in the previous period');
        $this->assertTrue($inToday);
    }
}
