<?php

namespace Tests\Feature\Schema;

use App\Enums\TillSessionStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $a;

    private Location $b;

    private ActiveScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        $this->scope = app(ActiveScope::class);
        $this->scope->setOrganisation($this->org->id);
        $this->a = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->b = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_a_per_location_record_is_invisible_when_another_location_is_active(): void
    {
        $this->scope->setLocation($this->a->id);
        $session = TillSession::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->a->id,
        ]);

        // Location B active — record from A is scoped out (denial).
        $this->scope->setLocation($this->b->id);
        $this->assertNull(TillSession::find($session->id));

        // "All locations" (owner rollup) — both cross the boundary.
        $this->scope->setLocation(null);
        $this->assertNotNull(TillSession::find($session->id));
    }

    public function test_organisation_id_and_location_id_auto_fill_from_the_active_scope(): void
    {
        $this->scope->setLocation($this->a->id);

        $session = TillSession::create([
            'status' => TillSessionStatus::OPEN,
            'float_cents' => 10000,
        ]);

        $this->assertSame($this->org->id, $session->organisation_id);
        $this->assertSame($this->a->id, $session->location_id);
    }

    public function test_org_wide_member_search_crosses_locations(): void
    {
        // Members are org-wide (not location-scoped): visible under any active location.
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        $this->scope->setLocation($this->a->id);
        $this->assertNotNull(Member::find($member->id));

        $this->scope->setLocation($this->b->id);
        $this->assertNotNull(Member::find($member->id));
    }
}
