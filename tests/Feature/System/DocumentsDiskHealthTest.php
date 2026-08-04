<?php

namespace Tests\Feature\System;

use App\ViewModels\SystemHealth;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

/**
 * Prompt 145 (S3 half) — `DOCUMENTS_DRIVER=s3` had never been exercised: the Flysystem S3 adapter was only a
 * Composer suggestion, so the entire object-storage path for Article 9 member documents would have thrown
 * `Class "…AwsS3V3…" not found` on the first write. This proves the adapter is now installed and that the
 * health panel reports a documents driver whose adapter is absent. Config checks only — no probe object.
 */
class DocumentsDiskHealthTest extends TestCase
{
    public function test_the_s3_documents_disk_resolves_and_its_adapter_class_exists(): void
    {
        // Would have FAILED against main: league/flysystem-aws-s3-v3 was not required.
        $this->assertTrue(class_exists(AwsS3V3Adapter::class));

        config(['filesystems.disks.documents' => array_merge((array) config('filesystems.disks.documents'), [
            'driver' => 's3', 'key' => 'key', 'secret' => 'secret', 'region' => 'eu-west-1', 'bucket' => 'bucket',
        ])]);
        Storage::forgetDisk('documents');

        // Constructs the S3 adapter (no network) — this threw on main with the package absent.
        $this->assertNotNull(Storage::disk('documents'));
    }

    public function test_the_health_check_flags_a_documents_driver_whose_adapter_is_absent(): void
    {
        // sftp's adapter (league/flysystem-sftp-v3) is not installed → reported unavailable.
        config(['filesystems.disks.documents.driver' => 'sftp']);

        $this->assertFalse((new SystemHealth)->documentsDisk()['available']);
    }

    public function test_the_health_check_reports_s3_available_now_that_the_adapter_is_installed(): void
    {
        config(['filesystems.disks.documents.driver' => 's3']);

        $this->assertTrue((new SystemHealth)->documentsDisk()['available']);
    }

    public function test_the_health_check_is_quiet_on_the_local_documents_disk(): void
    {
        config(['filesystems.disks.documents.driver' => 'local']);

        $health = (new SystemHealth)->documentsDisk();
        $this->assertSame('local', $health['driver']);
        $this->assertTrue($health['available']);
    }
}
