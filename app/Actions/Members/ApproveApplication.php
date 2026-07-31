<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Support\ActiveScope;
use App\Support\MemberEligibility;
use App\Support\MemberNumber;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Approve a pre-registration: age-gate, create the member with a generated
 * member_no and a carencia end date, record versioned consent, and mark the
 * application approved. Membership tier/fee is prompt 05. Audited & attributed.
 */
class ApproveApplication
{
    public function handle(MemberApplication $application, ?string $actorId = null): Member
    {
        app(ActiveScope::class)->setOrganisation($application->organisation_id);
        $payload = $application->payload ?? [];
        $actorId ??= Auth::id();

        if (! MemberEligibility::isOldEnough($payload['date_of_birth'] ?? null)) {
            throw new RuntimeException(__('El solicitante es menor de la edad mínima configurada.'));
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
        $member->status = MemberStatus::ACTIVE;
        $member->member_no = MemberNumber::next($application->organisation_id);
        $member->joined_at = now();
        $member->carencia_ends_at = now()->addDays((int) Settings::get('carencia_days', 15));
        $member->save();

        foreach (($payload['consents'] ?? ['membership', 'data_processing']) as $purpose) {
            $member->consents()->create([
                'purpose' => $purpose,
                'consent_text_version' => (string) Settings::get('consent_text_version', '1.0'),
                'granted_at' => now(),
                'ip' => request()->ip(),
            ]);
        }

        $application->update([
            'status' => ApplicationStatus::APPROVED,
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
            'resulting_member_id' => $member->id,
        ]);

        (new RecordAuditLog)->handle('application.approved', $member, null, ['application_id' => $application->id]);

        return $member;
    }
}
