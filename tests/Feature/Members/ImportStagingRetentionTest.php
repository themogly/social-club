<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ImportMembers;
use App\Console\Commands\PruneImportStaging;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\HeartbeatLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 142 — the scheduled sweep is the GUARANTEE that abandoned member-import staging CSVs (the club's whole
 * register in plaintext) do not linger. It removes files past the window and ONLY those; the explicit import
 * and cancel paths still delete their own stash immediately, and the preview→confirm handoff is unchanged.
 */
class ImportStagingRetentionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

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

        $this->dir = storage_path('app/member-imports');
        $this->clearDir();
    }

    protected function tearDown(): void
    {
        $this->clearDir();
        parent::tearDown();
    }

    private function clearDir(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*.csv') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function stage(string $contents, ?int $ageHours = null): string
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $path = $this->dir.DIRECTORY_SEPARATOR.Str::ulid().'.csv';
        file_put_contents($path, $contents);
        if ($ageHours !== null) {
            touch($path, now()->subHours($ageHours)->getTimestamp());
        }

        return $path;
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    public function test_the_sweep_deletes_a_staged_file_older_than_the_window(): void
    {
        $old = $this->stage("first_name\nA\n", ageHours: 9); // window default 4h

        $this->artisan('imports:prune-staging')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
    }

    public function test_the_sweep_leaves_a_recent_in_flight_file_alone(): void
    {
        $inFlight = $this->stage("first_name\nA\n"); // just written — mid-import

        $this->artisan('imports:prune-staging')->assertSuccessful();

        $this->assertFileExists($inFlight);
    }

    public function test_the_sweep_is_idempotent_and_safe_to_run_twice(): void
    {
        $this->stage("x\n", ageHours: 9);

        $this->artisan('imports:prune-staging')->assertSuccessful();
        $this->artisan('imports:prune-staging')->assertSuccessful(); // nothing left, no throw
        $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'*.csv') ?: []);
    }

    public function test_the_sweep_runs_cleanly_when_the_directory_is_absent(): void
    {
        $this->clearDir();
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }

        $this->artisan('imports:prune-staging')->assertSuccessful();
    }

    public function test_the_sweep_stamps_a_heartbeat_and_health_goes_stale_without_it(): void
    {
        // No run yet → stale.
        $this->assertTrue((new SystemHealth)->importStagingSweep()['stale']);

        $this->artisan('imports:prune-staging')->assertSuccessful();

        $this->assertNotNull(HeartbeatLog::query()->component(PruneImportStaging::HEARTBEAT)->first());
        $this->assertFalse((new SystemHealth)->importStagingSweep()['stale']);
    }

    public function test_a_successful_import_still_deletes_its_own_stash_immediately(): void
    {
        $stash = $this->stage("first_name,last_name,date_of_birth\nAna,García,1990-01-01\n");

        Livewire::actingAs($this->owner());
        Livewire::test(ListMembers::class)
            ->set('importStashPath', $stash)
            ->set('importPreview', ['created' => 1, 'skipped' => 0, 'errors' => [], 'consent_pending' => 0, 'ceilings' => []])
            ->callAction('confirmImport');

        $this->assertFileDoesNotExist($stash);                       // stash gone immediately
        $this->assertSame(1, Member::query()->where('first_name', 'Ana')->count()); // handoff: the import ran
    }

    public function test_an_explicit_cancel_still_deletes_its_own_stash_immediately(): void
    {
        $stash = $this->stage("first_name\nA\n");

        Livewire::actingAs($this->owner());
        Livewire::test(ListMembers::class)
            ->set('importStashPath', $stash)
            ->set('importPreview', ['created' => 1, 'skipped' => 0, 'errors' => [], 'consent_pending' => 0, 'ceilings' => []])
            ->callAction('cancelImport');

        $this->assertFileDoesNotExist($stash);
    }

    public function test_the_preview_carries_the_ceiling_and_consent_counts_intact(): void
    {
        // Comma-free names — the CSV is hand-built and unquoted, and a Location/Tier factory name can contain a
        // comma that would break the row (the exact fragility this test would otherwise hide).
        $location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Central']);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Basica']);
        // A member with a location+tier (→ active membership, so a ceiling is projected) and no consent_date
        // (→ counts toward consent_pending, prompt 131).
        $csv = "first_name,last_name,date_of_birth,location,tier\n"
            ."Ana,García,1990-01-01,{$location->name},{$tier->name}\n";
        $path = $this->stage($csv);

        $preview = (new ImportMembers)->preview($path);

        $this->assertSame(1, $preview['created']);
        $this->assertSame(1, $preview['consent_pending']);
        $this->assertNotEmpty($preview['ceilings']);
    }
}
