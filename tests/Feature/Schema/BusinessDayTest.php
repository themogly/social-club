<?php

namespace Tests\Feature\Schema;

use App\Models\Location;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessDayTest extends TestCase
{
    use RefreshDatabase;

    private function madridLocation(): Location
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        return Location::factory()->create([
            'organisation_id' => $org->id,
            'timezone' => 'Europe/Madrid',
            'business_day_cutoff' => '06:00',
        ]);
    }

    public function test_before_cutoff_belongs_to_the_previous_business_day(): void
    {
        $location = $this->madridLocation();

        $at = CarbonImmutable::parse('2026-03-15 02:00', 'Europe/Madrid');
        $this->assertSame('2026-03-14', BusinessDay::date($location, $at)->toDateString());

        // After the cutoff — same calendar day.
        $afterCutoff = CarbonImmutable::parse('2026-03-15 09:00', 'Europe/Madrid');
        $this->assertSame('2026-03-15', BusinessDay::date($location, $afterCutoff)->toDateString());
    }

    public function test_pre_cutoff_time_on_a_dst_transition_night_still_maps_to_the_previous_day(): void
    {
        $location = $this->madridLocation();

        // Spain springs forward 2026-03-29 (02:00 -> 03:00). 01:30 is a valid pre-cutoff local time.
        $at = CarbonImmutable::parse('2026-03-29 01:30', 'Europe/Madrid');
        $this->assertSame('2026-03-28', BusinessDay::date($location, $at)->toDateString());
    }

    public function test_window_spans_cutoff_to_cutoff(): void
    {
        $location = $this->madridLocation();

        [$start, $end] = BusinessDay::window($location, CarbonImmutable::parse('2026-03-15 09:00', 'Europe/Madrid'));

        $this->assertSame('2026-03-15 06:00', $start->format('Y-m-d H:i'));
        $this->assertSame('2026-03-16 06:00', $end->format('Y-m-d H:i'));
    }
}
