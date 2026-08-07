<?php

namespace Tests\Feature\Security;

use App\Support\DocumentUpload;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The panel's own file fields must never address the `documents` disk for a URL (security audit, Phase C
 * carry-forward — the work order asked whether every access still writes a DocumentAccessLog row now the
 * disk is remote, and for the panel's form fields the answer was no).
 *
 * Filament's `BaseFileUpload::getUploadedFile()` mints a URL for ANY `visibility('private')` field. On
 * `DOCUMENTS_DRIVER=s3` that is a presigned, bucket-direct URL to the object — reaching it runs no policy,
 * checks no `u` binding and writes no access-log row, because it never touches the application at all.
 */
class DocumentsDiskUrlTest extends TestCase
{
    private function field(): FileUpload
    {
        return FileUpload::make('document_scan_path')->disk('documents')->visibility('private');
    }

    public function test_filaments_default_would_hand_out_a_disk_url(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('member-id-scans/scan.pdf', 'ciphertext');

        // This is the behaviour being overridden — asserted so the fix is anchored to a real hazard rather
        // than to a belief about one. If a future Filament stops doing this, this test says so.
        $default = $this->field()->getUploadedFile('member-id-scans/scan.pdf', null);

        $this->assertNotNull($default);
        $this->assertNotNull($default['url'], 'Filament handed out no URL — the override may no longer be needed.');
    }

    public function test_the_override_hands_out_no_url_at_all(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('member-id-scans/scan.pdf', 'ciphertext');

        $resolved = (DocumentUpload::withoutDirectUrl())($this->field(), 'member-id-scans/scan.pdf', null);

        $this->assertNotNull($resolved);
        $this->assertNull($resolved['url'], 'A documents-disk field handed out a direct disk URL.');
        $this->assertSame('scan.pdf', $resolved['name']);
        $this->assertSame(10, $resolved['size']);   // the file is still described, just not linked
    }

    public function test_a_missing_file_resolves_to_nothing_rather_than_throwing(): void
    {
        Storage::fake('documents');

        $this->assertNull((DocumentUpload::withoutDirectUrl())($this->field(), 'member-id-scans/gone.pdf', null));
    }

    public function test_an_s3_documents_disk_can_presign_a_bucket_url_which_is_why_this_matters(): void
    {
        config()->set('filesystems.disks.documents.driver', 's3');
        config()->set('filesystems.disks.documents.root', '');
        config()->set('filesystems.disks.documents.bucket', 'csc-documents');
        config()->set('filesystems.disks.documents.region', 'eu-west-1');
        config()->set('filesystems.disks.documents.key', 'AKIATEST');
        config()->set('filesystems.disks.documents.secret', 'secrettest');
        Storage::forgetDisk('documents');

        $url = Storage::disk('documents')->temporaryUrl('member-id-scans/scan.pdf', now()->addMinutes(30));

        // Bucket-direct, signed, and nothing in the application sees the request. This is what the panel was
        // emitting into the page for every ID scan on the member edit form.
        $this->assertStringContainsString('csc-documents.s3.eu-west-1.amazonaws.com', $url);
        $this->assertStringContainsString('X-Amz-Signature', $url);
    }
}
