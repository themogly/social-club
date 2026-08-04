<?php

namespace Tests\Feature\Members;

use App\Actions\MemberAuth\IssueMemberLoginLink;
use App\Actions\Members\FindDuplicateMembers;
use App\Actions\Members\ImportMembers;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 146 — email is the login identifier, normalised (lowercase + trim) by the application, not by the DB
 * collation. These assertions must hold on SQLite (case-sensitive `=`) AND MySQL (case-insensitive collation),
 * which is exactly why normalisation is app-level; the MySQL parity job exercises the other half.
 */
class EmailNormalisationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    public function test_a_user_email_is_stored_lowercase_and_trimmed(): void
    {
        $user = User::factory()->create(['email' => '  Ben@Club.ES ']);

        $this->assertSame('ben@club.es', $user->fresh()->email);
        $this->assertSame('ben@club.es', DB::table('users')->where('id', $user->id)->value('email'));
    }

    public function test_a_user_logs_in_with_the_email_typed_in_any_case(): void
    {
        $user = User::factory()->create(['email' => 'Ben@Club.ES', 'password' => 'secret-pw']);
        $user->assignRole(Role::OWNER->value);

        foreach (['Ben@Club.ES', 'ben@club.es', 'BEN@CLUB.ES'] as $typed) {
            auth()->logout();
            Livewire::test(Login::class)
                ->fillForm(['email' => $typed, 'password' => 'secret-pw'])
                ->call('authenticate');
            $this->assertTrue(auth()->check(), "login should succeed for [{$typed}]");
        }
    }

    public function test_a_member_imported_with_mixed_case_email_is_stored_lowercase(): void
    {
        $csv = "first_name,last_name,email,date_of_birth\nAna,García,Mixed@Case.ES,1990-01-01\n";
        $path = tempnam(sys_get_temp_dir(), 'csc-email-test').'.csv';
        file_put_contents($path, $csv);

        (new ImportMembers)->import($path);
        @unlink($path);

        $member = Member::query()->where('first_name', 'Ana')->first();
        $this->assertNotNull($member);
        $this->assertSame('mixed@case.es', $member->email);
    }

    public function test_duplicate_detection_flags_a_case_only_email_difference(): void
    {
        $existing = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'ana@club.es']);

        $matches = (new FindDuplicateMembers)->handle(['email' => 'ANA@CLUB.ES']);

        $this->assertTrue($matches->contains('id', $existing->id));
    }

    public function test_issue_member_login_link_resolves_the_member_in_any_case(): void
    {
        Mail::fake();
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => 'socio@club.es', 'status' => MemberStatus::ACTIVE,
        ]);

        $this->assertTrue((new IssueMemberLoginLink)->handle('Socio@Club.ES'));
    }

    public function test_the_invite_applicant_email_is_normalised(): void
    {
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id, 'applicant_email' => 'Prospect@Club.ES',
        ]);

        $this->assertSame('prospect@club.es', $application->fresh()->applicant_email);
    }

    public function test_the_backfill_lowercases_existing_rows(): void
    {
        // Insert raw, bypassing the model cast, to simulate pre-normalisation data.
        $userId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $userId, 'name' => 'Legacy', 'email' => 'Legacy@Club.ES', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $memberId = (string) Str::ulid();
        DB::table('members')->insert([
            'id' => $memberId, 'organisation_id' => $this->org->id, 'member_no' => 'M-LEG',
            'first_name' => 'Leg', 'last_name' => 'Acy', 'email' => 'MixedCase@Club.ES',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame('legacy@club.es', DB::table('users')->where('id', $userId)->value('email'));
        $this->assertSame('mixedcase@club.es', DB::table('members')->where('id', $memberId)->value('email'));
    }

    public function test_the_backfill_reports_a_case_collision_instead_of_throwing_a_constraint_error(): void
    {
        // A case-only pair can only coexist under a case-sensitive collation; MySQL's CI unique index refuses
        // it at insert time, so this pre-existing-collision scenario is only constructible on SQLite.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A users.email case-collision cannot exist under a case-insensitive collation.');
        }

        DB::table('users')->insert([
            ['id' => (string) Str::ulid(), 'name' => 'A', 'email' => 'Dup@Club.ES', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'name' => 'B', 'email' => 'dup@club.es', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()],
        ]);

        try {
            $this->runBackfill();
            $this->fail('The backfill should refuse to run when lowercasing would collide.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dup@club.es', $e->getMessage());
            $this->assertStringContainsString('Dup@Club.ES', $e->getMessage());
            // It refused BEFORE writing: the rows are untouched.
            $this->assertSame('Dup@Club.ES', DB::table('users')->where('name', 'A')->value('email'));
        }
    }

    public function test_install_refuses_an_owner_email_that_differs_only_by_case(): void
    {
        User::factory()->create(['email' => 'owner@club.es']);

        $this->artisan('csc:install', [
            '--name' => 'Club', '--legal-name' => 'Club SL', '--owner-name' => 'Owner',
            '--owner-email' => 'Owner@Club.ES', '--owner-password' => 'secret-pw',
        ])->assertFailed();
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_13_000000_normalise_existing_emails.php');
        $migration->up();
    }
}
