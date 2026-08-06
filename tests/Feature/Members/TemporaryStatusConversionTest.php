<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ManageTemporaryMember;
use App\Enums\MemberKind;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\StockCeiling;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 165 — a member's temporary status was set once at creation and could never be changed.
 *
 * Half of this already existed: `convertTemporaryAction` (temporary → standard) and
 * `extendTemporaryAction` were wired to `ManageTemporaryMember` on the record. What had NO path at
 * all was the other direction — a standard member made temporary, which is what a flag set in error
 * at the counter needs, and what a member asking to be treated as a short-stay visitor needs.
 *
 * The load-bearing rule of the new direction: the window starts at the CONVERSION, never at the join
 * date. A retroactive window would expire a long-standing member instantly, and the sweep does not
 * merely hide an expired temporary member — it ANONYMISES them.
 */
class TemporaryStatusConversionTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        Settings::set('temporary_members_enabled', true, SettingType::BOOL);
        Settings::set('temporary_window_days', 30, SettingType::INT);
    }

    private function member(MemberKind $kind = MemberKind::STANDARD, ?string $expires = null): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'kind' => $kind,
            'joined_at' => now()->subYears(3),        // long-standing: a retroactive window would expire them
            'temporary_expires_at' => $expires,
        ]);
    }

    private function actor(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    // --- Standard → temporary: the direction that did not exist -----------------------------------

    public function test_a_standard_member_can_be_made_temporary_with_a_window_that_starts_now(): void
    {
        $member = $this->member();

        (new ManageTemporaryMember)->convertToTemporary($member, 'Pasa a estancia corta');

        $member->refresh();
        $this->assertSame(MemberKind::TEMPORARY, $member->kind);
        $this->assertTrue($member->temporary_expires_at->isFuture());
        // 30 days from TODAY, not from a joined_at three years ago.
        $this->assertSame(30, (int) round(now()->diffInDays($member->temporary_expires_at)));
    }

    public function test_the_window_is_never_retroactive_so_the_member_is_not_instantly_expirable(): void
    {
        $member = $this->member();

        (new ManageTemporaryMember)->convertToTemporary($member);

        // The sweep anonymises an expired temporary member. Running it immediately after the
        // conversion must be a no-op — a retroactive window here would erase a three-year member.
        $this->artisan('members:remove-temporary')->assertSuccessful();

        $member->refresh();
        $this->assertNull($member->anonymised_at);
        $this->assertSame(MemberKind::TEMPORARY, $member->kind);
    }

    public function test_a_fresh_window_gets_its_own_expiry_reminder(): void
    {
        // temporary_reminder_sent_at is the sweep's one-reminder-per-window marker. A stale one from an
        // earlier temporary stint would silently swallow the new window's warning.
        $member = $this->member(MemberKind::TEMPORARY, now()->subDay()->toDateTimeString());
        $member->forceFill(['temporary_reminder_sent_at' => now()->subMonth()])->save();

        (new ManageTemporaryMember)->convertToStandard($member);
        (new ManageTemporaryMember)->convertToTemporary($member->refresh());

        $this->assertNull($member->refresh()->temporary_reminder_sent_at);
    }

    // --- Temporary → standard: the sweep must never reach a converted member ----------------------

    public function test_a_member_converted_to_permanent_an_hour_before_the_sweep_is_not_removed(): void
    {
        $member = $this->member(MemberKind::TEMPORARY, now()->subDays(2)->toDateTimeString());

        $this->travelTo(now()->subHour());
        (new ManageTemporaryMember)->convertToStandard($member);
        $this->travelBack();

        $this->artisan('members:remove-temporary')->assertSuccessful();

        $member->refresh();
        $this->assertNull($member->anonymised_at);
        $this->assertSame(MemberKind::STANDARD, $member->kind);
        $this->assertNull($member->temporary_expires_at);
    }

    // --- Neither direction may create a second person ---------------------------------------------

    public function test_neither_direction_creates_a_duplicate_member(): void
    {
        $member = $this->member();
        $id = $member->getKey();

        (new ManageTemporaryMember)->convertToTemporary($member);
        (new ManageTemporaryMember)->convertToStandard($member->refresh());

        $this->assertSame(1, Member::query()->withoutGlobalScopes()->count());
        $this->assertSame($id, Member::query()->withoutGlobalScopes()->sole()->getKey());
    }

    // --- The counts the prompt asks about ---------------------------------------------------------

    /**
     * The legal stock ceiling is computed from ACTIVE members holding an ACTIVE membership at the
     * location — `StockCeiling::forLocation()` never reads `kind`. So a conversion cannot move it in
     * either direction, which is the answer to "does the ceiling still agree?": it is invariant by
     * construction, and this pins that so a future change to the ceiling query cannot silently make a
     * conversion shift the premises limit.
     */
    public function test_the_stock_ceiling_is_unchanged_by_a_conversion_in_either_direction(): void
    {
        $member = $this->member();
        Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'member_id' => $member->getKey(),
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->getKey(),
            'status' => MembershipStatus::ACTIVE,
        ]);

        $before = StockCeiling::forLocation($this->location);
        $this->assertSame(1, $before['active_members']);

        (new ManageTemporaryMember)->convertToTemporary($member);
        $asTemporary = StockCeiling::forLocation($this->location->fresh());

        (new ManageTemporaryMember)->convertToStandard($member->refresh());
        $backToStandard = StockCeiling::forLocation($this->location->fresh());

        $this->assertSame($before['active_members'], $asTemporary['active_members']);
        $this->assertSame($before['ceiling_cg'], $asTemporary['ceiling_cg']);
        $this->assertSame($before['active_members'], $backToStandard['active_members']);
        $this->assertSame($before['ceiling_cg'], $backToStandard['ceiling_cg']);
    }

    public function test_the_member_cap_count_follows_the_conversion_under_both_cap_settings(): void
    {
        $member = $this->member();

        // Counting temporary members: the headcount is the same whichever kind they are.
        Settings::set('temporary_count_toward_cap', true, SettingType::BOOL);
        $this->assertSame(1, $this->cappedCount());
        (new ManageTemporaryMember)->convertToTemporary($member);
        $this->assertSame(1, $this->cappedCount());

        // NOT counting them: converting to temporary must take them out of the count, and converting
        // back must return them — a conversion changes the ceiling headroom, so it is not cosmetic.
        Settings::set('temporary_count_toward_cap', false, SettingType::BOOL);
        $this->assertSame(0, $this->cappedCount());
        (new ManageTemporaryMember)->convertToStandard($member->refresh());
        $this->assertSame(1, $this->cappedCount());
    }

    /** The same predicate the dashboard's active-member cap uses. */
    private function cappedCount(): int
    {
        return (int) Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->org->id)
            ->where('status', MemberStatus::ACTIVE->value)
            ->when(! (bool) Settings::get('temporary_count_toward_cap', true),
                fn ($q) => $q->where('kind', MemberKind::STANDARD->value))
            ->count();
    }

    // --- Audit -------------------------------------------------------------------------------------

    public function test_the_conversion_is_audited_with_both_values_the_reason_and_the_actor(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $member = $this->member();

        (new ManageTemporaryMember)->convertToTemporary($member, 'Se marcó por error en el mostrador');

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'member.temporary.applied')->sole();
        $this->assertSame($actor->getKey(), $log->actor_id);
        $this->assertSame($member->getKey(), $log->auditable_id);
        $this->assertSame(MemberKind::STANDARD->value, $log->before['kind']);
        $this->assertNull($log->before['temporary_expires_at']);
        $this->assertSame(MemberKind::TEMPORARY->value, $log->after['kind']);
        $this->assertNotNull($log->after['temporary_expires_at']);
        $this->assertSame('Se marcó por error en el mostrador', $log->after['reason']);
    }

    // --- Reachability + gating ----------------------------------------------------------------------

    public function test_a_manager_sees_the_action_on_a_standard_member(): void
    {
        $this->actingAs($this->actor(Role::MANAGER));
        $member = $this->member();

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->assertActionVisible('makeTemporary')
            ->assertActionHidden('convertTemporary');       // already standard — nothing to convert
    }

    public function test_the_action_is_hidden_on_a_member_who_is_already_temporary(): void
    {
        $this->actingAs($this->actor(Role::MANAGER));
        $member = $this->member(MemberKind::TEMPORARY, now()->addDays(10)->toDateTimeString());

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->assertActionHidden('makeTemporary')
            ->assertActionVisible('convertTemporary');
    }

    public function test_with_temporary_members_disabled_the_action_is_unavailable(): void
    {
        Settings::set('temporary_members_enabled', false, SettingType::BOOL);
        $this->actingAs($this->actor(Role::MANAGER));
        $member = $this->member();

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->assertActionHidden('makeTemporary');
    }

    public function test_switching_the_feature_off_still_lets_a_club_rescue_its_existing_temporary_members(): void
    {
        // Deliberately NOT gated on the setting: turning the feature off must stop new temporary
        // members being made, never strand the ones already carrying an auto-expiry.
        Settings::set('temporary_members_enabled', false, SettingType::BOOL);
        $this->actingAs($this->actor(Role::MANAGER));
        $member = $this->member(MemberKind::TEMPORARY, now()->addDays(5)->toDateTimeString());

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->assertActionVisible('convertTemporary');
    }

    public function test_a_staff_user_without_the_permission_cannot_convert(): void
    {
        $staff = $this->actor(Role::STAFF);
        $this->assertFalse($staff->can('members.create'), 'members.create is the gate the action reads.');

        // Denied a step earlier than the action: staff cannot reach the member edit page at all, so
        // there is no surface on which to mount the conversion.
        $this->actingAs($staff);
        $this->get(MemberResource::getUrl('edit', ['record' => $this->member()]))->assertForbidden();
    }
}
