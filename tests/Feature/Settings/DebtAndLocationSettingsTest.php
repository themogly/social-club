<?php

namespace Tests\Feature\Settings;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Filament\Pages\ManageSettings;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;
use App\Support\Settings;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class DebtAndLocationSettingsTest extends TestCase
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

    private function member(bool $withMembership = false): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'date_of_birth' => now()->subYears(30)]);
        if ($withMembership) {
            $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
            Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
                'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
            ]);
        }

        return $member;
    }

    public function test_the_configured_debt_limit_is_what_the_counter_actually_enforces(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 5000, SettingType::CENTS);

        // Within the configured €50 limit → allowed.
        (new RecordWalletTransaction)->handle($this->member(), $this->location, -4000, WalletTransactionType::CONTRIBUTION);

        // Beyond it → blocked by the CONFIGURED limit (not a hardcoded one).
        try {
            (new RecordWalletTransaction)->handle($this->member(), $this->location, -6000, WalletTransactionType::CONTRIBUTION);
            $this->fail('A €60 debt should exceed the configured €50 limit.');
        } catch (DebtLimitExceededException) {
            // expected
        }

        // Raise the limit through Settings (what the form writes) → the same debt now passes.
        Settings::set('wallet_debt_limit_cents', 10000, SettingType::CENTS);
        $m = $this->member();
        (new RecordWalletTransaction)->handle($m, $this->location, -6000, WalletTransactionType::CONTRIBUTION);
        $this->assertSame(-6000, Wallet::balance($m->id, $this->location->id));
    }

    public function test_the_door_threshold_and_the_hard_limit_are_independent(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_door_debt_threshold_cents', 2000, SettingType::CENTS);
        Settings::set('wallet_debt_limit_cents', 5000, SettingType::CENTS);

        $member = $this->member(withMembership: true);
        (new RecordWalletTransaction)->handle($member, $this->location, -3000, WalletTransactionType::CONTRIBUTION, ['allow_debt' => true]);

        // The DOOR reacts at the €20 threshold (debt €30 > €20).
        $door = (new ResolveMemberEligibility)->handle($member, $this->location, 'door');
        $this->assertContains(__('Deuda por encima del umbral.'), array_column($door->warnings(), 'message'));

        // The COUNTER still allows debt up to the SEPARATE €50 hard limit (→ €40).
        (new RecordWalletTransaction)->handle($member, $this->location, -1000, WalletTransactionType::CONTRIBUTION);
        $this->assertSame(-4000, Wallet::balance($member->id, $this->location->id));

        // Raise ONLY the door threshold → the door goes quiet, the hard limit is untouched.
        Settings::set('wallet_door_debt_threshold_cents', 6000, SettingType::CENTS);
        $door2 = (new ResolveMemberEligibility)->handle($member, $this->location, 'door');
        $this->assertNotContains(__('Deuda por encima del umbral.'), array_column($door2->warnings(), 'message'));

        // The €50 hard limit still blocks (→ would be €60).
        try {
            (new RecordWalletTransaction)->handle($member, $this->location, -2000, WalletTransactionType::CONTRIBUTION);
            $this->fail('The counter should still block beyond the unchanged €50 hard limit.');
        } catch (DebtLimitExceededException) {
            // expected
        }
    }

    public function test_assigning_two_locations_offers_exactly_those_in_the_switcher(): void
    {
        $b = Location::factory()->create(['organisation_id' => $this->org->id]);
        $c = Location::factory()->create(['organisation_id' => $this->org->id]);

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->location->id, $b->id]);

        $switcher = new LocationSwitcher;
        $available = $switcher->available($staff)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->location->id, $b->id], $available);
        $this->assertFalse($switcher->canSwitchToAll($staff));        // not an owner
        $this->assertTrue($switcher->canAccess($staff, $this->location->id));
        $this->assertTrue($switcher->canAccess($staff, $b->id));
        $this->assertFalse($switcher->canAccess($staff, $c->id));     // C is not assigned → unreachable
    }

    public function test_owner_gets_all_locations_regardless_of_the_picker(): void
    {
        Location::factory()->create(['organisation_id' => $this->org->id]);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value); // no explicit location assignment

        $switcher = new LocationSwitcher;
        $this->assertGreaterThanOrEqual(2, $switcher->available($owner)->count()); // all org sedes
        $this->assertTrue($switcher->canSwitchToAll($owner));
    }

    public function test_a_manager_with_zero_locations_has_no_accessible_sede(): void
    {
        // Decision: zero locations for a non-owner is a deliberate "no access yet" state.
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);

        $switcher = new LocationSwitcher;
        $this->assertCount(0, $switcher->available($manager));
        $this->assertFalse($switcher->canAccess($manager, $this->location->id));
    }

    public function test_the_settings_page_covers_every_org_configurable_setting(): void
    {
        // Repeatable completeness gate: every organisation-level DEFAULT is either an
        // editable scalar on the form, a money/weight edge field, or a documented exclusion.
        $scalars = (new ReflectionClass(ManageSettings::class))->getConstant('SCALARS');
        $editable = array_keys(is_array($scalars) ? $scalars : []);

        // Money/weight/percent values edited via *_eur / *_g / *_pct virtual fields → cover their stored keys.
        // minute_quorum_fraction_bp is entered as a percentage (prompt 44).
        $edgeCovered = ['daily_limit_cg', 'monthly_limit_cg', 'wallet_debt_limit_cents', 'wallet_door_debt_threshold_cents', 'low_balance_threshold_cents', 'arqueo_variance_tolerance_cents', 'expense_approval_threshold_cents', 'minute_quorum_fraction_bp'];

        // Deliberately NOT on the org settings form (documented in DECISIONS): the enforcement
        // matrix (its own editor), per-location settings, and system/compliance constants.
        // (default_locale + enabled_locales are now ON the form — prompt 44 — so they left this list.)
        $excluded = ['enforcement', 'aforo_default', 'data_retention_days', 'audit_retention_days', 'signed_url_ttl_seconds', 'consent_text_version', 'consent_privacy_text', 'consent_statutes_text', 'heartbeat_stale_seconds', 'monthly_window',
            // Scheduler constant, not a front-of-house threshold: how many hours before an event to push
            // its reminder (prompt 56 — the events:remind command reads it).
            'event_reminder_lead_hours',
            // Per-location counter settings, edited on each LocationForm (not the org page): the POS
            // check-in / signature requirements (prompt 44 — now genuinely per-location) + camera QR (prompt 35).
            'restrict_pos_to_checked_in', 'signature_on_dispensation', 'camera_scan_enabled',
            // Per-location toggles reconciled to Setting rows (prompt 59/102), edited on LocationForm.
            'bar_enabled', 'ring_fenced', 'multiple_tills_enabled',
            // Per-location INTEGER setting, edited on LocationForm (prompt 120): idle-lock minutes.
            'counter_idle_lock_minutes',
            // Panic-lockdown system settings (prompt 121): the auto-reactivation delay and the owner link TTL are
            // security constants tuned in config, not front-of-house thresholds on the org settings form.
            'lockdown_auto_reactivate_minutes', 'lockdown_reactivation_link_ttl_hours',
            // Per-location numeric-LIST setting, edited on LocationForm (prompt 133): POS weight presets.
            'pos_weight_presets_g',
            // Documented in DECISIONS: forecast_options_g is a preset ARRAY (a tags/repeater
            // input is a later enhancement); low_stock_threshold_cg is a fallback — the operative
            // low-stock threshold is set per-article on the Article resource.
            'forecast_options_g', 'low_stock_threshold_cg'];

        $covered = array_merge($editable, $edgeCovered, $excluded);

        $gaps = array_values(array_diff(array_keys(Settings::DEFAULTS), $covered));

        $this->assertSame([], $gaps, 'Org settings with no form field and no documented exclusion: '.implode(', ', $gaps));

        // The door-threshold gap stays covered (wallet_ring_fence + limit_override_requires_manager
        // were CUT in prompt 34 as inert controls, so they are no longer DEFAULTS/on the form).
        $this->assertContains('wallet_door_debt_threshold_cents', $edgeCovered);
        $this->assertArrayNotHasKey('wallet_ring_fence', Settings::DEFAULTS);
    }
}
