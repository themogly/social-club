<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ManageTemporaryMember;
use App\Enums\IdDocumentType;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\Dispensation;
use App\Models\HeartbeatLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\TemporaryAccessEndingNotification;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 31 — temporary / short-stay members. The load-bearing rule: temporary status
 * changes list visibility and retention timing ONLY — it never loosens a compliance
 * check. Auto-removal always goes through the existing anonymise-not-delete erasure
 * Action, keeping ledger totals intact.
 */
class TemporaryMemberTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Member $avalador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('members');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->avalador = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        Settings::set('temporary_members_enabled', true, SettingType::BOOL);
        Settings::set('temporary_window_days', 30, SettingType::INT);
        // A configured VAPID keypair so the expiry-reminder push actually routes to its channel under
        // Notification::fake() (via() gates on VAPID); the reminder assertions below would be vacuous without it.
        config(['webpush.vapid.public_key' => 'BPUBLICKEY', 'webpush.vapid.private_key' => 'PRIVATEKEY']);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);

        return $owner;
    }

    // --- Enrolment ------------------------------------------------------------------

    public function test_enrolling_a_temporary_member_computes_the_expiry_from_the_window(): void
    {
        // Freeze the clock (prompt 125): enrolment calls now() inside MemberEnrolment::defaults(), so without
        // this the test races the clock and a sub-day drift used to truncate 30 → 29 under full-suite timing.
        $this->freezeTime();
        // Read the RULE from Settings, never a hard-coded 30 — the window is temporary_window_days.
        $window = (int) Settings::get('temporary_window_days');

        $this->actingAs($this->owner());

        Livewire::test(CreateMember::class)
            ->fillForm([
                'first_name' => 'Vera', 'last_name' => 'Viajera', 'email' => 'vera@example.test',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'document_type' => IdDocumentType::DNI->value, 'document_number' => '12345678Z',
                'is_therapeutic' => false, 'avalador_member_id' => $this->avalador->id,
                'declared_monthly_cg' => '50.00', 'consent_given' => true,
                'is_temporary' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $member = Member::query()->withoutGlobalScopes()->where('first_name', 'Vera')->firstOrFail();
        $this->assertTrue($member->isTemporary());
        $this->assertNotNull($member->temporary_expires_at);
        // Assert the RULE as timestamps, not a truncated diff: expiry is EXACTLY joined_at + the window. This
        // still fails if the code computes from the wrong base or the wrong window.
        $this->assertTrue(
            $member->temporary_expires_at->equalTo($member->joined_at->copy()->addDays($window)),
            "temporary_expires_at must equal joined_at + {$window} days (temporary_window_days).",
        );
    }

    public function test_changing_the_temporary_window_changes_the_computed_expiry(): void
    {
        // The test tracks the SETTING, it does not encode a magic number (prompt 125).
        $this->freezeTime();
        Settings::set('temporary_window_days', 45, SettingType::INT);

        $this->actingAs($this->owner());
        Livewire::test(CreateMember::class)
            ->fillForm([
                'first_name' => 'Nadia', 'last_name' => 'Nómada', 'email' => 'nadia@example.test',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'document_type' => IdDocumentType::DNI->value, 'document_number' => '87654321X',
                'is_therapeutic' => false, 'avalador_member_id' => $this->avalador->id,
                'declared_monthly_cg' => '50.00', 'consent_given' => true,
                'is_temporary' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $member = Member::query()->withoutGlobalScopes()->where('first_name', 'Nadia')->firstOrFail();
        $this->assertTrue($member->temporary_expires_at->equalTo($member->joined_at->copy()->addDays(45)));
    }

    // --- The load-bearing rule: identical compliance -------------------------------

    public function test_no_compliance_check_branches_on_the_temporary_kind(): void
    {
        // A temporary member must be checked IDENTICALLY — the shared resolvers must not
        // even know the concept exists, so there is no place a shortcut could hide.
        foreach ([
            'Actions/Attendance/ResolveMemberEligibility.php',
            'Actions/Dispensing/CommitDispensation.php',
            'Actions/Dispensing/ResolveMemberLimits.php',
        ] as $relative) {
            $src = (string) file_get_contents(app_path($relative));
            foreach (['isTemporary', 'temporary_expires_at', 'MemberKind', 'TEMPORARY'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($relative)." must not branch on temporary kind ({$needle}).");
            }
        }
    }

    // --- Directory ------------------------------------------------------------------

    public function test_the_directory_excludes_temporary_by_default_and_the_filter_returns_them(): void
    {
        $standard = Member::factory()->create(['organisation_id' => $this->org->id, 'kind' => MemberKind::STANDARD]);
        $temporary = Member::factory()->create(['organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY, 'temporary_expires_at' => now()->addDays(10)]);

        Livewire::actingAs($this->owner())->test(ListMembers::class)
            ->assertCanSeeTableRecords([$standard])
            ->assertCanNotSeeTableRecords([$temporary])                       // excluded by default
            ->filterTable('kind', MemberKind::TEMPORARY->value)
            ->assertCanSeeTableRecords([$temporary])                          // the filter returns exactly them
            ->assertCanNotSeeTableRecords([$standard]);
    }

    // --- Auto-removal sweep ---------------------------------------------------------

    public function test_the_sweep_anonymises_past_window_temporaries_and_keeps_ledger_totals(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->subDay(), 'first_name' => 'Tomás',
        ]);
        Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'member_id' => $member->id, 'total_cents' => 3500, 'cash_cents' => 3500, 'wallet_cents' => 0,
        ]);
        $ledgerBefore = (int) Dispensation::withoutGlobalScopes()->where('member_id', $member->id)->sum('total_cents');

        $this->artisan('members:remove-temporary')->assertSuccessful();

        $member->refresh();
        $this->assertNotNull($member->anonymised_at);                        // anonymise-not-delete
        $this->assertNotSame('Tomás', $member->first_name);                  // personal data scrubbed
        // Consumption/financial ledger, still attributed to the (now anonymised) record, is intact.
        $ledgerAfter = (int) Dispensation::withoutGlobalScopes()->where('member_id', $member->id)->sum('total_cents');
        $this->assertSame($ledgerBefore, $ledgerAfter);
        $this->assertSame(3500, $ledgerAfter);
    }

    public function test_the_sweep_is_idempotent_and_honours_dry_run(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->subDay(),
        ]);

        // Dry-run reports but does NOT anonymise.
        $this->artisan('members:remove-temporary --dry-run')->assertSuccessful();
        $this->assertNull($member->fresh()->anonymised_at);

        // Real run anonymises; a second run is a no-op (already anonymised → skipped).
        $this->artisan('members:remove-temporary')->assertSuccessful();
        $anonymisedAt = $member->fresh()->anonymised_at;
        $this->assertNotNull($anonymisedAt);

        $this->artisan('members:remove-temporary')->assertSuccessful();
        $this->assertEquals($anonymisedAt, $member->fresh()->anonymised_at); // unchanged — not re-processed

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.anonymised']);
    }

    // --- Expiry reminder (prompt 111) -----------------------------------------------

    public function test_the_sweep_reminds_a_member_inside_the_lead_window_once(): void
    {
        Notification::fake();
        Settings::set('temporary_reminder_lead_days', 3, SettingType::INT);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->addDays(2),   // inside the 3-day lead, not yet expired
        ]);

        $this->artisan('members:remove-temporary')->assertSuccessful();

        Notification::assertSentTo($member, TemporaryAccessEndingNotification::class);
        $this->assertNotNull($member->fresh()->temporary_reminder_sent_at);  // idempotency marker stamped
        $this->assertNull($member->fresh()->anonymised_at);                  // reminded, not removed

        // A second nightly run does not re-send — the marker makes it a no-op.
        Notification::fake();
        $this->artisan('members:remove-temporary')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_the_sweep_does_not_remind_before_the_lead_window(): void
    {
        Notification::fake();
        Settings::set('temporary_reminder_lead_days', 3, SettingType::INT);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->addDays(10),  // well beyond the lead
        ]);

        $this->artisan('members:remove-temporary')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($member->fresh()->temporary_reminder_sent_at);
    }

    public function test_dry_run_neither_reminds_nor_stamps_the_marker(): void
    {
        Notification::fake();
        Settings::set('temporary_reminder_lead_days', 3, SettingType::INT);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->addDay(),
        ]);

        $this->artisan('members:remove-temporary --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($member->fresh()->temporary_reminder_sent_at);
    }

    // --- Convert / extend -----------------------------------------------------------

    public function test_converting_to_standard_clears_the_expiry_and_the_sweep_skips_them(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->subDay(),   // already past → would be swept
        ]);

        (new ManageTemporaryMember)->convertToStandard($member);

        $member->refresh();
        $this->assertSame(MemberKind::STANDARD, $member->kind);
        $this->assertNull($member->temporary_expires_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.temporary.converted']);

        // The sweep no longer touches them.
        $this->artisan('members:remove-temporary')->assertSuccessful();
        $this->assertNull($member->fresh()->anonymised_at);
    }

    public function test_extending_pushes_the_window_out_and_is_audited(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => MemberKind::TEMPORARY,
            'temporary_expires_at' => now()->addDay(),
        ]);
        $before = $member->temporary_expires_at;

        (new ManageTemporaryMember)->extend($member, 30);

        $this->assertTrue($member->fresh()->temporary_expires_at->greaterThan($before->addDays(29)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.temporary.extended']);
    }

    // --- Health panel ---------------------------------------------------------------

    public function test_the_health_panel_tracks_the_temporary_sweep(): void
    {
        $health = new SystemHealth;
        $this->assertTrue($health->temporarySweep()['stale']);        // never run → red

        $this->artisan('members:remove-temporary')->assertSuccessful();
        $this->assertFalse((new SystemHealth)->temporarySweep()['stale']); // stamped → green

        HeartbeatLog::query()->component('temporary-sweep')->update(['ran_at' => now()->subDays(2)]);
        $this->assertTrue((new SystemHealth)->temporarySweep()['stale']);  // old → red
    }
}
