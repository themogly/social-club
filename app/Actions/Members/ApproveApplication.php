<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\ApplicationStatus;
use App\Exceptions\DuplicateMemberException;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Support\ActiveScope;
use App\Support\MemberEligibility;
use App\Support\MemberEnrolment;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Approve a pre-registration: age-gate, create the member with a generated
 * member_no and a carencia end date, record versioned consent, and mark the
 * application approved. Membership tier/fee is prompt 05. Audited & attributed.
 */
class ApproveApplication
{
    public function handle(MemberApplication $application, ?string $actorId = null, bool $allowDuplicate = false): Member
    {
        app(ActiveScope::class)->setOrganisation($application->organisation_id);
        $payload = $application->payload ?? [];
        $actorId ??= Auth::id();

        if (! MemberEligibility::isOldEnough($payload['date_of_birth'] ?? null)) {
            throw new RuntimeException(__('El solicitante es menor de la edad mínima configurada.'));
        }

        // Search-before-create — approving into an existing member would split their balance and
        // history. Blocked unless the reviewer explicitly overrides (a genuine same-name other person).
        if (! $allowDuplicate) {
            $duplicates = (new FindDuplicateMembers)->handle($payload);
            if ($duplicates->isNotEmpty()) {
                throw DuplicateMemberException::forMatches($duplicates);
            }
        }

        $member = new Member([
            'first_name' => $payload['first_name'] ?? '',
            'last_name' => $payload['last_name'] ?? '',
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'date_of_birth' => $payload['date_of_birth'] ?? null,
            'address' => $payload['address'] ?? null,
            'document_type' => $payload['document_type'] ?? null,
            'document_number' => $payload['document_number'] ?? null,
            'is_therapeutic' => (bool) ($payload['is_therapeutic'] ?? false),
            'avalador_member_id' => $payload['avalador_member_id'] ?? null,
            'declared_monthly_cg' => $payload['declared_monthly_cg'] ?? null,
        ]);
        $member->organisation_id = $application->organisation_id;
        // Shared enrolment defaults — the SAME source the direct-create form fills, so the
        // carencia rule / active status / member-number generation can't drift (prompt 37).
        $member->fill(MemberEnrolment::defaults($application->organisation_id));
        $member->save();

        // Stamp the version the applicant actually SAW at submit (prompt 97), not the current one, so a text
        // revision between application and approval can never rewrite what they agreed to.
        $consentVersion = isset($payload['consent_version']) && is_string($payload['consent_version']) ? $payload['consent_version'] : null;
        foreach (($payload['consents'] ?? ['membership', 'data_processing']) as $purpose) {
            (new RecordMemberConsent)->handle($member, $purpose, request()->ip(), $consentVersion);
        }

        $application->update([
            'status' => ApplicationStatus::APPROVED,
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
            'resulting_member_id' => $member->id,
        ]);

        (new RecordAuditLog)->handle('application.approved', $member, null, ['application_id' => $application->id]);

        // Send the QR card automatically (prompt 85). Called ONCE here — the member is fully created; the
        // admin CreateMember page's afterCreate does not run on this path, so there is no double-send.
        (new SendMemberCard)->handle($member);

        return $member;
    }
}
