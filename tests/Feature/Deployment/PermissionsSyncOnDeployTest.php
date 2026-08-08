<?php

namespace Tests\Feature\Deployment;

use App\Enums\Role as RoleEnum;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\PermissionDrift;
use App\Support\Permissions;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Prompt 214 — a permission added in code never reached a club that was already installed.
 *
 * The owner, logged in as **Club Owner**, on the dispensary: *"says ask manager but I'm an owner."* The screen
 * read *"Ask a manager: you do not have permission to create one."* The code was right — on a freshly seeded
 * database owner, manager and staff can all enrol, because prompt 203 granted `membership.enrol` to all three.
 * **His database was seeded before 203 landed**, so the permission row and its role assignments did not exist
 * there and `can()` returned false for everyone.
 *
 * That is not a local quirk. `RolePermissionSeeder` is idempotent by design and is exactly the right thing to
 * run after a permission changes — and it was called from **one place**, `csc:install`, which runs once and
 * refuses a second run without `--force`. The deploy sequence re-synced nothing. So every club kept its
 * install-day matrix for ever, and the failure was **silent**: no error, no log line, only an operator refused
 * something the code says they may do.
 *
 * The **revoked** direction is the more serious one and is asserted explicitly below: `Permissions::for()` is
 * the file everyone treats as the source of truth for who may do what, while the database quietly keeps a
 * grant deleted from the code months ago.
 */
class PermissionsSyncOnDeployTest extends TestCase
{
    use RefreshDatabase;

    private function ownerAtASede(): User
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::OWNER->value);
        $user->locations()->sync([$location->id]);

        return $user;
    }

    /** Roll the database back to a matrix from before prompt 203 — which is what the owner's club held. */
    private function seedTheOldMatrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (RoleEnum::cases() as $case) {
            $role = Role::query()->where('name', $case->value)->firstOrFail();
            $role->revokePermissionTo('membership.enrol');
        }

        Permission::query()->where('name', 'membership.enrol')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // --- The owner's screenshot, reproduced ---------------------------------------------------

    /**
     * A database seeded with an old matrix converges after the sync.
     *
     * **Fails against `main`**: there is nothing on `main` that re-syncs a club after `csc:install`, so the
     * `can()` assertion below stays false for ever. This is the owner's screenshot as a test.
     */
    public function test_a_database_with_an_old_matrix_converges(): void
    {
        $this->seedTheOldMatrix();
        $owner = $this->ownerAtASede();

        $this->assertFalse($owner->can('membership.enrol'), 'precondition: the old matrix really is missing it');

        $this->assertSame(0, Artisan::call('csc:sync-permissions'));

        foreach (RoleEnum::cases() as $case) {
            $role = Role::query()->where('name', $case->value)->firstOrFail();
            $this->assertTrue(
                $role->hasPermissionTo('membership.enrol'),
                $case->value.' still cannot enrol after the sync',
            );
        }

        // …and `can()` is correct IN THIS PROCESS, which is the permission-cache half.
        $this->assertTrue($owner->fresh()->can('membership.enrol'), 'the permission cache was not busted');
    }

    /**
     * **The security half**: a grant that is no longer in `Permissions::for()` is revoked.
     *
     * Asserted explicitly rather than by trusting `syncPermissions()` to do it, because this is the direction
     * nobody would look for and the one that must never be softened into an additive merge.
     */
    public function test_a_grant_the_code_no_longer_lists_is_revoked(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // A permission that exists in the catalogue but is NOT in STAFF's matrix — granted directly to the
        // role, the way a stale grant from an older release would sit there.
        $stale = collect(Permissions::ALL)
            ->first(fn (string $p): bool => ! in_array($p, Permissions::for(RoleEnum::STAFF), true));
        $this->assertNotNull($stale, 'no permission exists outside the STAFF matrix to test with');

        $staff = Role::query()->where('name', RoleEnum::STAFF->value)->firstOrFail();
        $staff->givePermissionTo($stale);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($staff->fresh()->hasPermissionTo($stale), 'precondition: the stale grant is in place');
        $this->assertArrayHasKey(RoleEnum::STAFF->value, PermissionDrift::report()['stale_grants']);

        Artisan::call('csc:sync-permissions');

        $this->assertFalse(
            Role::query()->where('name', RoleEnum::STAFF->value)->firstOrFail()->hasPermissionTo($stale),
            'a permission the code has withdrawn is still live',
        );
    }

    /** Per-USER grants outside the role matrix survive — this syncs roles, not people. */
    public function test_a_direct_user_grant_survives_the_sync(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();

        $direct = collect(Permissions::ALL)
            ->first(fn (string $p): bool => ! in_array($p, Permissions::for(RoleEnum::STAFF), true));
        $user->givePermissionTo($direct);
        $this->assertTrue($user->fresh()->can($direct));

        Artisan::call('csc:sync-permissions');

        $this->assertTrue($user->fresh()->can($direct), 'the sync revoked a per-user grant it does not own');
    }

    /** Idempotent: the second run changes nothing and says so. */
    public function test_the_sync_is_idempotent(): void
    {
        $this->seedTheOldMatrix();

        Artisan::call('csc:sync-permissions');
        $afterFirst = PermissionDrift::report();

        $this->assertSame(0, Artisan::call('csc:sync-permissions'));
        $this->assertStringContainsString('already matched', Artisan::output());
        $this->assertSame($afterFirst, PermissionDrift::report());
    }

    // --- Making the silence visible ------------------------------------------------------------

    /** `--check` reports drift, exits non-zero, and writes nothing. */
    public function test_check_reports_drift_without_writing(): void
    {
        $this->seedTheOldMatrix();

        $this->assertSame(1, Artisan::call('csc:sync-permissions', ['--check' => true]));
        $this->assertStringContainsString('membership.enrol', Artisan::output());

        // Nothing was written: the drift is still there.
        $this->assertFalse(PermissionDrift::report()['in_sync'], '--check wrote to the database');
    }

    /** …and reports clean when they agree. */
    public function test_check_is_clean_when_the_matrix_matches(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(0, Artisan::call('csc:sync-permissions', ['--check' => true]));
        $this->assertTrue(PermissionDrift::report()['in_sync']);
    }

    /**
     * The health page sees it too — because closing the silence only in the deploy script leaves anyone who
     * deployed some other way still blind.
     */
    public function test_system_health_reports_permission_drift(): void
    {
        $this->seedTheOldMatrix();
        $health = new SystemHealth;

        $drifted = $health->permissions();
        $this->assertFalse($drifted['in_sync']);
        $this->assertNotEmpty($drifted['lines']);
        $this->assertStringContainsString('membership.enrol', implode(' ', $drifted['lines']));

        Artisan::call('csc:sync-permissions');

        $clean = (new SystemHealth)->permissions();
        $this->assertTrue($clean['in_sync']);
        $this->assertSame([], $clean['lines']);
    }

    /** The drift report names BOTH directions, so a reader can tell which one they are looking at. */
    public function test_the_report_distinguishes_missing_from_stale(): void
    {
        $this->seedTheOldMatrix();

        $stale = collect(Permissions::ALL)
            ->first(fn (string $p): bool => ! in_array($p, Permissions::for(RoleEnum::STAFF), true));
        Role::query()->where('name', RoleEnum::STAFF->value)->firstOrFail()->givePermissionTo($stale);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $report = PermissionDrift::report();

        $this->assertContains('membership.enrol', $report['missing_permissions']);
        $this->assertArrayHasKey(RoleEnum::OWNER->value, $report['missing_grants']);
        $this->assertArrayHasKey(RoleEnum::STAFF->value, $report['stale_grants']);
        $this->assertFalse($report['in_sync']);
    }

    // --- What must still work ------------------------------------------------------------------

    /** `csc:install` still works on a clean database, and still refuses a second run without --force. */
    public function test_install_still_works_and_still_refuses_a_second_run(): void
    {
        $options = [
            '--name' => 'Club Verde',
            '--legal-name' => 'Asociación Club Verde',
            '--tax-id' => 'G12345678',
            '--contact-email' => 'hola@example.es',
            '--owner-name' => 'Club Owner',
            '--owner-email' => 'owner@example.es',
            '--owner-password' => 'secret-password',
        ];

        $this->assertSame(0, Artisan::call('csc:install', $options));
        $this->assertSame(1, Organisation::query()->count());
        $this->assertTrue(PermissionDrift::report()['in_sync'], 'a fresh install is not in sync with the code');

        $options['--owner-email'] = 'second@example.es';
        $this->assertSame(1, Artisan::call('csc:install', $options), 'a second install was not refused');
        $this->assertSame(1, Organisation::query()->count());
    }
}
