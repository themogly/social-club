<?php

namespace App\Actions\Members;

use App\Enums\MemberDocumentType;
use App\Models\Member;
use App\Models\User;

/**
 * Mirror the sensitive scan columns (ID document, medical certificate) into
 * MemberDocument rows so the existing signed-URL + access-log machinery
 * (IssueDocumentUrl → DocumentAccessLog) serves them — the scans are never
 * exposed through a plain, un-logged disk URL.
 *
 * One current row per type (updateOrCreate keyed on type): re-uploading a scan
 * updates the pointer rather than stacking versions, which is the right shape for
 * "the member's current ID / medical document". Called from the member Create and
 * Edit pages after the record is persisted.
 */
class SyncMemberScanDocuments
{
    public function handle(Member $member, ?User $actor = null): void
    {
        $this->upsert($member, MemberDocumentType::ID, $member->document_scan_path, $actor);
        $this->upsert($member, MemberDocumentType::MEDICAL, $member->medical_cert_path, $actor);
    }

    private function upsert(Member $member, MemberDocumentType $type, ?string $path, ?User $actor): void
    {
        if (blank($path)) {
            return;
        }

        $member->documents()->updateOrCreate(
            ['type' => $type->value],
            ['path' => $path, 'uploaded_by' => $actor?->id],
        );
    }
}
