<?php

namespace Tests\Feature\Ops;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Reset\ArrayKeyStore;
use App\Support\Reset\DatabaseDump;
use App\Support\Reset\RedisPurger;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 304 — `csc:reset-for-launch` wipes the test club and installs the real one, safely, once; `csc:launch` is the
 * one-way latch that blocks it (and `csc:install --force`) forever after. Every path here runs under a TEMPORARY
 * storage path — the real storage/app is never touched by these tests.
 */
class ResetForLaunchTest extends TestCase
{
    private string $storage;

    private Organisation $org;

    /** @var list<array<string, string>> */
    public static array $purged = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Not RefreshDatabase: the reset runs a real `migrate:fresh`, which SQLite cannot do inside the test's transaction.
        // Each test's in-memory database is its own, so it is simply migrated here (no rollback afterwards).
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->storage = sys_get_temp_dir().'/csc-reset-'.bin2hex(random_bytes(4));
        foreach (['app/public/genetics', 'app/member-imports', 'app/private/documents/member-id-scans', 'framework/views', 'framework/cache', 'framework/sessions'] as $dir) {
            File::ensureDirectoryExists($this->storage.'/'.$dir);
        }
        $this->app->useStoragePath($this->storage);
        config([
            'filesystems.disks.public.root' => $this->storage.'/app/public',
            'filesystems.disks.documents.root' => $this->storage.'/app/private/documents',
            'filesystems.disks.documents.driver' => 'local',
        ]);
        Storage::forgetDisk(['public', 'documents']);

        File::put($this->storage.'/app/public/.gitignore', "*\n!.gitignore\n");
        File::put($this->storage.'/app/public/genetics/strain.jpg', 'jpeg');
        File::put($this->storage.'/app/member-imports/staged.csv', 'a,b');
        File::put($this->storage.'/app/private/documents/member-id-scans/scan.enc', 'enc');

        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create(['name' => 'Club de Prueba']);
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $owner = User::factory()->create(['email' => 'prueba@example.test']);
        $owner->assignRole(Role::OWNER->value);
        Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Socio', 'last_name' => 'Deprueba']);

        self::$purged = [];
        // Never a real Redis in these tests (CI may have none): the pre-flight passes, the purge is recorded.
        $this->app->instance(RedisPurger::class, new class extends RedisPurger
        {
            public function check(): void {}

            public function purge(): int
            {
                ResetForLaunchTest::$purged[] = $this->prefixes();

                return 3;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function confirmed(array $options = []): PendingCommand
    {
        return $this->artisan('csc:reset-for-launch', $options)
            ->expectsQuestion(__('Escribe el nombre de la asociación para confirmar'), 'Club de Prueba')
            ->expectsConfirmation(__('Se borrarán TODOS los datos de :name. ¿Continuar?', ['name' => 'Club de Prueba']), 'yes');
    }

    private function untouched(): void
    {
        $this->assertSame(1, Organisation::query()->count());
        $this->assertFileExists($this->storage.'/app/public/genetics/strain.jpg');
        $this->assertFileExists($this->storage.'/app/member-imports/staged.csv');
        $this->assertFileExists($this->storage.'/app/private/documents/member-id-scans/scan.enc');
        $this->assertSame([], self::$purged, 'Redis was flushed');
        $this->assertFalse($this->app->isDownForMaintenance(), 'the site was left down');
    }

    // --- 1–3. Refusals -------------------------------------------------------------------------------------------------

    public function test_a_launched_club_can_never_be_reset(): void
    {
        $this->org->forceFill(['launched_at' => now()->subDay()])->save();

        $this->artisan('csc:reset-for-launch', ['--no-install' => true])
            ->expectsOutputToContain(__('Este club ya está en marcha. El reinicio está bloqueado para siempre.'))
            ->assertFailed();

        $this->untouched();
    }

    public function test_a_wrong_name_or_a_no_changes_nothing(): void
    {
        $this->artisan('csc:reset-for-launch', ['--no-install' => true])
            ->expectsQuestion(__('Escribe el nombre de la asociación para confirmar'), 'Club de prueba')
            ->assertFailed();
        $this->untouched();

        $this->artisan('csc:reset-for-launch', ['--no-install' => true])
            ->expectsQuestion(__('Escribe el nombre de la asociación para confirmar'), 'Club de Prueba')
            ->expectsConfirmation(__('Se borrarán TODOS los datos de :name. ¿Continuar?', ['name' => 'Club de Prueba']), 'no')
            ->assertFailed();
        $this->untouched();
    }

    public function test_an_active_lockdown_refuses(): void
    {
        OrganisationLockdown::create(['organisation_id' => $this->org->id, 'locked_at' => now(), 'is_drill' => true]);

        $this->artisan('csc:reset-for-launch', ['--no-install' => true])->assertFailed();

        $this->untouched();
    }

    public function test_unreachable_redis_is_found_before_anything_is_touched(): void
    {
        $this->app->instance(RedisPurger::class, new class extends RedisPurger
        {
            public function check(): void
            {
                throw new RuntimeException('Connection refused');
            }
        });

        $this->confirmed(['--no-install' => true])->assertFailed();

        $this->untouched();
        $this->assertSame([], glob($this->storage.'/app/backups/*') ?: [], 'a dump was taken for a reset that could not finish');
    }

    // --- 4. The dump comes first ------------------------------------------------------------------------------------------

    public function test_the_dump_is_written_first_private_and_holds_the_old_data(): void
    {
        $this->confirmed(['--no-install' => true])->assertSuccessful();

        $dumps = glob($this->storage.'/app/backups/pre-launch-reset-*.sql.gz') ?: [];
        $this->assertCount(1, $dumps);
        $this->assertGreaterThan(0, filesize($dumps[0]));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dumps[0])), -4));
        $this->assertStringContainsString('Club de Prueba', (string) gzdecode((string) file_get_contents($dumps[0])), 'the dump was not taken before the wipe');
    }

    public function test_a_failed_dump_wipes_nothing_and_brings_the_site_back(): void
    {
        $this->app->instance(DatabaseDump::class, new class extends DatabaseDump
        {
            public function write(string $path): void
            {
                throw new RuntimeException('mysqldump: command not found');
            }
        });

        $this->confirmed(['--no-install' => true])->assertFailed();

        $this->untouched();
    }

    // --- 5–6. The wipe ------------------------------------------------------------------------------------------------------

    public function test_the_wipe_empties_the_data_the_files_and_the_apps_redis_keys_only(): void
    {
        $this->confirmed(['--no-install' => true])->assertSuccessful();

        $this->assertSame(0, Organisation::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
        foreach (['app/public', 'app/member-imports', 'app/private/documents'] as $dir) {
            $this->assertDirectoryExists($this->storage.'/'.$dir);
            $left = array_map(fn ($f) => $f->getFilename(), File::allFiles($this->storage.'/'.$dir, true));
            $this->assertSame($dir === 'app/public' ? ['.gitignore'] : [], $left, "{$dir} was not emptied (or lost its .gitignore)");
        }
        $this->assertCount(1, self::$purged);
        $this->assertFalse($this->app->isDownForMaintenance());
    }

    public function test_the_purger_deletes_only_keys_under_the_apps_prefixes(): void
    {
        $store = new ArrayKeyStore(['csc-database-queues:default', 'csc-database-cache:x', 'csc_horizon:jobs', 'otherapp-database-cache:y', 'unprefixed']);
        $purger = new RedisPurger(fn (): ArrayKeyStore => $store, ['csc-database-', 'csc_horizon:']);

        $this->assertSame(3, $purger->purge());
        $this->assertSame(['otherapp-database-cache:y', 'unprefixed'], $store->keys());
    }

    public function test_remote_documents_are_deleted_only_with_the_flag_and_its_own_confirmation(): void
    {
        config(['filesystems.disks.documents.driver' => 's3']);
        Storage::fake('documents');
        Storage::disk('documents')->put('member-id-scans/a.enc', 'x');
        Storage::disk('documents')->put('member-photos/b.enc', 'y');

        $this->confirmed(['--no-install' => true])->expectsOutputToContain('2')->assertSuccessful();
        $this->assertCount(2, Storage::disk('documents')->allFiles());

        $this->seed(RolePermissionSeeder::class);
        Organisation::factory()->create(['name' => 'Club de Prueba']);
        $this->confirmed(['--no-install' => true, '--purge-documents' => true])
            ->expectsConfirmation(__('Se borrarán :count objetos del almacén de documentos. ¿Continuar?', ['count' => 2]), 'yes')
            ->assertSuccessful();
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    // --- 7. A full run --------------------------------------------------------------------------------------------------------

    public function test_a_full_run_installs_one_club_and_one_owner_and_records_no_personal_data(): void
    {
        $this->confirmed()
            ->expectsQuestion('Association name', 'Asociación Real')
            ->expectsQuestion('Registered legal name (data controller on the RAT)', 'Asociación Real')
            ->expectsQuestion('Tax id (CIF/NIF)', 'G12345678')
            ->expectsQuestion('Association contact email', 'hola@real.test')
            ->expectsQuestion('First owner — full name', 'Dueña Real')
            ->expectsQuestion('First owner — login email', 'duena@real.test')
            ->expectsQuestion('First owner — password (min 8 chars)', 'una-clave-larga')
            ->assertSuccessful();

        $this->assertSame(['Asociación Real'], Organisation::query()->pluck('name')->all());
        $this->assertSame(['duena@real.test'], User::query()->pluck('email')->all());
        $audit = AuditLog::query()->where('action', 'system.reset_for_launch')->sole();
        $payload = (string) json_encode($audit->toArray());
        foreach (['duena@real.test', 'Dueña Real', 'Club de Prueba', 'prueba@example.test', 'Deprueba'] as $personal) {
            $this->assertStringNotContainsString($personal, $payload);
        }
        $this->assertStringContainsString('pre-launch-reset-', $payload);
    }

    // --- 8. The launch latch ----------------------------------------------------------------------------------------------

    public function test_launch_is_a_one_way_latch_that_blocks_the_reset_and_a_second_install(): void
    {
        $this->assertSame(['launched' => false, 'since' => null], (new SystemHealth)->launch());

        $this->artisan('csc:launch')->expectsConfirmation(__('¿Marcar :name como en marcha? Después no se podrá reiniciar.', ['name' => 'Club de Prueba']), 'yes')
            ->expectsOutputToContain(__('Club marcado como en marcha.'))->assertSuccessful();
        $launchedAt = $this->org->fresh()->launched_at;
        $this->assertNotNull($launchedAt);
        $this->assertSame(['launched' => true, 'since' => $launchedAt->format('d/m/Y')], (new SystemHealth)->launch());
        $this->assertSame(1, AuditLog::query()->where('action', 'system.launched')->count());

        $this->travel(1)->day();
        $this->artisan('csc:launch')->assertSuccessful();
        $this->assertEquals($launchedAt, $this->org->fresh()->launched_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'system.launched')->count());

        $this->artisan('csc:reset-for-launch', ['--no-install' => true])->assertFailed();
        $this->artisan('csc:install', ['--force' => true])->assertFailed();
        $this->assertSame(1, Organisation::query()->count());
    }
}
