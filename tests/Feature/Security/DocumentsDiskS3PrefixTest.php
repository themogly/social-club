<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\DocumentVault;
use App\Support\VaultUrl;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter as LaravelAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as Flysystem;
use Tests\TestCase;

/**
 * Prompt 162 — Laravel's `createS3Driver()` passes the disk's `root` to the S3 adapter as the object-KEY
 * PREFIX. The documents disk's `root` was `storage_path('app/private/documents')`, so on S3 every ID scan /
 * medical cert / photo was keyed under the server's ABSOLUTE filesystem path — which leaks the server layout
 * into the bucket and, fatally for a disk with no second copy, makes every object unreachable after any move,
 * rename, redeploy or restore (the prefix is re-derived from wherever the app now lives). The fix makes `root`
 * a LOCAL-driver concept; S3 gets an explicit prefix defaulting to EMPTY. These assert the LITERAL key.
 */
class DocumentsDiskS3PrefixTest extends TestCase
{
    use RefreshDatabase;

    /** Re-evaluate config/filesystems.php as if DOCUMENTS_DRIVER (and optionally a prefix) were set. */
    private function documentsRoot(string $driver, ?string $prefix = null): string
    {
        $prevDriver = getenv('DOCUMENTS_DRIVER');
        $prevPrefix = getenv('DOCUMENTS_S3_PREFIX');
        $this->setEnv('DOCUMENTS_DRIVER', $driver);
        if ($prefix !== null) {
            $this->setEnv('DOCUMENTS_S3_PREFIX', $prefix);
        }

        try {
            return (string) (require config_path('filesystems.php'))['disks']['documents']['root'];
        } finally {
            $this->setEnv('DOCUMENTS_DRIVER', $prevDriver);
            $this->setEnv('DOCUMENTS_S3_PREFIX', $prevPrefix);
        }
    }

    private function setEnv(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Bind the `documents` disk to a stateful in-memory S3 that captures every object Key.
     *
     * @param  array<string, list<string>>  $keys  op name → keys written
     * @param  array<string, string>  $store  key → stored (ciphertext) body
     */
    private function bindS3Documents(string $root, array &$keys, array &$store): void
    {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => function (CommandInterface $cmd) use (&$keys, &$store) {
                $name = $cmd->getName();
                $key = $cmd['Key'] ?? null;
                if ($key !== null) {
                    $keys[$name][] = $key;
                }
                if ($name === 'PutObject') {
                    $store[$key] = (string) $cmd['Body'];
                } elseif ($name === 'GetObject') {
                    return Create::promiseFor(new Result(['Body' => Utils::streamFor($store[$key] ?? ''), '@metadata' => ['statusCode' => 200]]));
                } elseif ($name === 'HeadObject') {
                    return Create::promiseFor(new Result(['ContentLength' => strlen($store[$key] ?? ''), '@metadata' => ['statusCode' => 200]]));
                }

                return Create::promiseFor(new Result(['@metadata' => ['statusCode' => 200]]));
            },
        ]);

        $adapter = new AwsS3V3Adapter($client, 'docs-bucket', $root);
        Storage::set('documents', new LaravelAdapter(new Flysystem($adapter), $adapter, ['throw' => true]));
    }

    // --- The config: `root` is a LOCAL-driver concept -------------------------------

    public function test_the_documents_root_is_local_only(): void
    {
        $this->assertStringEndsWith('storage/app/private/documents', $this->documentsRoot('local'));
        $this->assertSame('', $this->documentsRoot('s3'));                            // S3 → flat bucket root
        $this->assertSame('clubs/verde', $this->documentsRoot('s3', 'clubs/verde'));  // explicit seam, opt-in
    }

    // --- The literal S3 object key --------------------------------------------------

    public function test_the_s3_object_key_is_the_bare_path_with_no_absolute_prefix(): void
    {
        $keys = [];
        $store = [];
        $this->bindS3Documents($this->documentsRoot('s3'), $keys, $store);

        $path = 'member-id-scans/'.Str::ulid().'.jpg';
        DocumentVault::put($path, 'ciphertext');

        $key = $keys['PutObject'][0] ?? null;
        $this->assertSame($path, $key); // the literal object key — a bare, app-namespaced path

        foreach (['storage', 'home', '/Users', base_path()] as $absoluteSegment) {
            $this->assertStringNotContainsString($absoluteSegment, (string) $key);
        }
    }

    public function test_a_round_trip_on_s3_returns_the_exact_original_bytes(): void
    {
        $keys = [];
        $store = [];
        $this->bindS3Documents($this->documentsRoot('s3'), $keys, $store);

        $path = 'member-photos/'.Str::ulid().'.jpg';
        DocumentVault::put($path, 'the-original-bytes');

        $this->assertNotSame('the-original-bytes', $store[$path]);        // ciphertext at rest
        $this->assertSame('the-original-bytes', DocumentVault::get($path)); // decrypts to the exact original
    }

    // --- The local driver is UNCHANGED ----------------------------------------------

    public function test_the_local_driver_writes_under_storage_and_round_trips(): void
    {
        Storage::fake('documents'); // fake == local flysystem; the local root behaviour
        $path = 'member-id-scans/'.Str::ulid().'.jpg';

        DocumentVault::put($path, 'original');

        Storage::disk('documents')->assertExists($path);
        $this->assertNotSame('original', Storage::disk('documents')->get($path)); // ciphertext at rest
        $this->assertSame('original', DocumentVault::get($path));                 // exact bytes back
    }

    // --- The general `s3` disk was never affected -----------------------------------

    public function test_the_general_s3_disk_has_no_root(): void
    {
        // It never set a `root`, so it never carried the leak — assert it stays flat.
        $this->assertArrayNotHasKey('root', (array) config('filesystems.disks.s3'));
    }

    // --- The signed-URL serving path is UNTOUCHED -----------------------------------

    public function test_the_signed_url_endpoint_still_serves_and_logs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('documents');
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$location->id]);

        $path = 'member-photos/'.Str::ulid().'.png';
        DocumentVault::put($path, 'IMGBYTES');
        $member = Member::factory()->create(['organisation_id' => $org->id, 'photo_path' => $path]);

        $this->actingAs($staff)
            ->withSession(['scope.organisation_id' => $org->id])
            ->get(VaultUrl::photo($member, $staff))
            ->assertOk();

        $this->assertSame(1, DocumentAccessLog::query()
            ->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->count());
    }
}
