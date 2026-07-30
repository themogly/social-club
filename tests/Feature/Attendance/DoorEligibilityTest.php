<?php

namespace Tests\Feature\Attendance;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\EligibilityVerdict;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoorEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 2]);
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0, // no fee due
        ]);

        return $member;
    }

    private function verdict(Member $member): EligibilityVerdict
    {
        return (new ResolveMemberEligibility)->handle($member, $this->location, 'door');
    }

    public function test_a_fully_eligible_member_is_clear(): void
    {
        $this->assertTrue($this->verdict($this->eligibleMember())->isClear());
    }

    public function test_no_active_membership_blocks(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay()]);
        $this->assertTrue($this->verdict($member)->isBlocked());
    }

    public function test_under_age_blocks(): void
    {
        $member = $this->eligibleMember();
        $member->update(['date_of_birth' => now()->subYears(16)]);
        $this->assertTrue($this->verdict($member->fresh())->isBlocked());
    }

    public function test_a_suspended_member_blocks(): void
    {
        $member = $this->eligibleMember();
        $member->update(['status' => MemberStatus::SUSPENDED]);
        $this->assertTrue($this->verdict($member->fresh())->isBlocked());
    }

    public function test_carencia_only_warns_at_the_door(): void
    {
        $member = $this->eligibleMember();
        $member->update(['carencia_ends_at' => now()->addDays(10)]);
        $verdict = $this->verdict($member->fresh());

        $this->assertFalse($verdict->isBlocked());     // may enter...
        $this->assertNotEmpty($verdict->warnings());    // ...but flagged
    }

    public function test_aforo_blocks_at_capacity(): void
    {
        $member = $this->eligibleMember();
        CheckIn::factory()->count(2)->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'checked_out_at' => null]);

        $this->assertTrue($this->verdict($member)->isBlocked()); // capacity 2, 2 inside
    }

    public function test_debt_over_threshold_only_warns(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 100000, SettingType::CENTS);
        Settings::set('wallet_door_debt_threshold_cents', 1000, SettingType::CENTS);
        $member = $this->eligibleMember();
        (new RecordWalletTransaction)->handle($member, $this->location, -5000, WalletTransactionType::ADJUSTMENT, ['allow_debt' => true]);

        $verdict = $this->verdict($member);
        $this->assertFalse($verdict->isBlocked());   // debt at the door warns, not blocks
        $this->assertSame(-5000, Wallet::balance($member->id, $this->location->id));
    }
}
