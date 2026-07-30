<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\SettingType;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveMemberLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Member $member;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);
    }

    private function daily(): int
    {
        return (new ResolveMemberLimits)->handle($this->member, $this->location)->dailyLimitCg;
    }

    public function test_falls_back_to_the_org_default(): void
    {
        $this->assertSame(350, $this->daily()); // Settings default (3.5 g)
    }

    public function test_location_setting_beats_the_org_default(): void
    {
        Settings::set('daily_limit_cg', 300, SettingType::CG, $this->location->id);
        $this->assertSame(300, $this->daily());
    }

    public function test_tier_limit_beats_location_and_org(): void
    {
        Settings::set('daily_limit_cg', 300, SettingType::CG, $this->location->id);
        $this->tier->update(['daily_limit_cg' => 250]);
        $this->assertSame(250, $this->daily());
    }

    public function test_member_override_beats_everything(): void
    {
        Settings::set('daily_limit_cg', 300, SettingType::CG, $this->location->id);
        $this->tier->update(['daily_limit_cg' => 250]);
        $this->member->update(['daily_limit_cg' => 200]);
        $this->assertSame(200, $this->daily());
    }

    private function seedUsage(int $gramsCg, string $dispensedAt): void
    {
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'status' => DispensationStatus::COMPLETED, 'dispensed_at' => $dispensedAt,
            'total_cents' => 0, 'cash_cents' => 0, 'wallet_cents' => 0,
        ]);
        DispensationLine::factory()->create([
            'dispensation_id' => $dispensation->id,
            'genetic_id' => Genetic::factory()->create(['organisation_id' => $this->org->id])->id,
            'grams_cg' => $gramsCg,
        ]);
    }

    public function test_calendar_month_used_resets_next_month(): void
    {
        $this->seedUsage(500, now()->startOfMonth()->addDays(2)->toDateTimeString());

        $thisMonth = (new ResolveMemberLimits)->handle($this->member, $this->location, now()->startOfMonth()->addDays(10));
        $this->assertSame(500, $thisMonth->monthlyUsedCg);

        $nextMonth = (new ResolveMemberLimits)->handle($this->member, $this->location, now()->addMonthNoOverflow()->startOfMonth()->addDays(3));
        $this->assertSame(0, $nextMonth->monthlyUsedCg); // calendar reset
    }

    public function test_rolling_30_day_window_drops_old_usage(): void
    {
        Settings::set('monthly_window', 'rolling30');
        $reference = now();
        $this->seedUsage(500, $reference->copy()->subDays(40)->toDateTimeString()); // outside 30-day window

        $snapshot = (new ResolveMemberLimits)->handle($this->member, $this->location, $reference);
        $this->assertSame(0, $snapshot->monthlyUsedCg);
    }
}
