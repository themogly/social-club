<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DispensationStatus;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\ViewModels\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prompt 79 — the dashboard's "members over limit" alert ran a DispensationLine sum query PER member inside a
 * ->get()->filter() closure (the audit measured ~401 queries / ~20 s on the landing page). The rewrite is a
 * single aggregate; this pins the query count so the N+1 cannot silently return, and checks the count stays
 * correct.
 */
class MembersOverLimitPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function memberWithLimit(int $limitCg, ?int $dispensedCg = null): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'monthly_limit_cg' => $limitCg,
        ]);

        if ($dispensedCg !== null) {
            $dispensation = Dispensation::factory()->create([
                'organisation_id' => $this->org->id,
                'member_id' => $member->id,
                'location_id' => $this->location->id,
                'status' => DispensationStatus::COMPLETED,
                'dispensed_at' => now(),
            ]);
            DispensationLine::factory()->create([
                'dispensation_id' => $dispensation->id,
                'grams_cg' => $dispensedCg,
            ]);
        }

        return $member;
    }

    public function test_it_is_set_based_and_correct_regardless_of_member_count(): void
    {
        // Six members with an override: two over their limit, four with no dispensations (under).
        $this->memberWithLimit(1000, 1500);
        $this->memberWithLimit(1000, 1200);
        for ($i = 0; $i < 4; $i++) {
            $this->memberWithLimit(1000);
        }

        $dashboard = new Dashboard($this->org->id, null, Period::thisMonth());

        DB::connection()->enableQueryLog();
        $count = $dashboard->membersOverLimit();
        $queries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertSame(2, $count, 'Two members are over their monthly override.');
        // Old code: 1 (members) + one per member = 7 for six members. New: 2, flat. Guard well below N.
        $this->assertLessThanOrEqual(3, $queries, "membersOverLimit ran {$queries} queries — the per-member N+1 is back.");
    }

    public function test_it_short_circuits_when_no_member_has_an_override(): void
    {
        Member::factory()->count(3)->create(['organisation_id' => $this->org->id, 'monthly_limit_cg' => null]);

        $dashboard = new Dashboard($this->org->id, null, Period::thisMonth());

        DB::connection()->enableQueryLog();
        $this->assertSame(0, $dashboard->membersOverLimit());
        // No override members → one query to discover that, and no aggregate at all.
        $this->assertLessThanOrEqual(1, count(DB::connection()->getQueryLog()));
        DB::connection()->disableQueryLog();
    }
}
