<?php

namespace Tests\Feature\Settings;

use App\Actions\Wallet\AutoSettleDebt;
use App\Enums\MemberKind;
use App\Enums\MembershipStatus;
use App\Enums\SettingType;
use App\Filament\Pages\ManageSettings;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Period;
use App\Support\Settings;
use App\ViewModels\Dashboard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Prompt 34 — the eight inert settings are now each WIRED or CUT. A control that renders
 * but does nothing is worse than none, so a cut setting is fully removed (not left rendering).
 */
class InertSettingsResolvedTest extends TestCase
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
    }

    private function activeMember(MemberKind $kind = MemberKind::STANDARD): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'kind' => $kind]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    private function dashboard(): Dashboard
    {
        return new Dashboard($this->org->id, [$this->location->id], Period::today());
    }

    // 1 — active_member_cap WIRED (dashboard alert)
    public function test_the_active_member_cap_fires_a_dashboard_alert_when_reached(): void
    {
        Settings::set('active_member_cap', 2, SettingType::INT);
        $this->assertSame(0, $this->dashboard()->membersOverCap());   // below cap → no alert

        $this->activeMember();
        $this->activeMember();                                        // now at the cap of 2

        $this->assertSame(2, $this->dashboard()->membersOverCap());
        $this->assertContains('active_member_cap', array_column($this->dashboard()->alerts(), 'key'));
    }

    public function test_temporary_count_toward_cap_controls_whether_temporary_members_count(): void
    {
        Settings::set('active_member_cap', 1, SettingType::INT);
        $this->activeMember();                          // 1 standard
        $this->activeMember(MemberKind::TEMPORARY);     // 1 temporary

        Settings::set('temporary_count_toward_cap', true, SettingType::BOOL);
        $this->assertSame(2, $this->dashboard()->membersOverCap());   // both counted

        Settings::set('temporary_count_toward_cap', false, SettingType::BOOL);
        $this->assertSame(1, $this->dashboard()->membersOverCap());   // temporary excluded
    }

    // 3 — ring-fence RESOLVED (per-location is the real one; org toggle cut)
    public function test_the_per_location_ring_fenced_setting_governs_auto_settle(): void
    {
        $this->assertArrayNotHasKey('wallet_ring_fence', Settings::DEFAULTS);   // the inert org toggle is cut

        $fenced = Location::factory()->create(['organisation_id' => $this->org->id]);
        $unfenced = Location::factory()->create(['organisation_id' => $this->org->id]);
        Settings::set('ring_fenced', true, SettingType::BOOL, $fenced->id); // location-scoped Setting row (prompt 59)

        $this->assertTrue(AutoSettleDebt::isRingFenced($fenced));
        $this->assertFalse(AutoSettleDebt::isRingFenced($unfenced)); // no override → org/default false
    }

    // 4,5,6,7,8 — CUT: gone from DEFAULTS and (where applicable) the form
    public function test_the_cut_controls_are_removed_not_merely_hidden(): void
    {
        foreach (['currency_locale', 'fees_to_wallet_allowed', 'wallet_ring_fence', 'limit_override_requires_manager', 'blind_count_enforced'] as $key) {
            $this->assertArrayNotHasKey($key, Settings::DEFAULTS, "{$key} must be cut from DEFAULTS.");
        }

        /** @var array<string, mixed> $scalars */
        $scalars = (new ReflectionClass(ManageSettings::class))->getConstant('SCALARS');
        foreach (['currency_locale', 'fees_to_wallet_allowed', 'wallet_ring_fence', 'limit_override_requires_manager'] as $key) {
            $this->assertArrayNotHasKey($key, $scalars, "{$key} must be off the settings form.");
        }
    }
}
