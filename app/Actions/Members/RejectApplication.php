<?php

namespace App\Actions\Members;

use App\Actions\Mail\QueueClubMail;
use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Enums\ApplicationStatus;
use App\Mail\ApplicationRejectedMail;
use App\Models\MemberApplication;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Reject a submitted application (post-296 completeness P4 — it was inline in the resource and unaudited, while approval
 * is an Action). Only a PENDING, submitted application; records who decided and when, audits `application.rejected`
 * (the application and the reviewer — never the address, never the reason's text, which stays on the application), and
 * tells the applicant in the language they applied in (288), else the club default.
 */
class RejectApplication
{
    /**
     * @throws DomainException when the application is not awaiting a decision
     */
    public function handle(MemberApplication $application, User $actor, ?string $reason): MemberApplication
    {
        if ($application->status !== ApplicationStatus::PENDING || $application->submitted_at === null) {
            throw new DomainException(__('Esta solicitud ya no está pendiente de decisión.'));
        }

        DB::transaction(function () use ($application, $actor, $reason): void {
            $application->update([
                'status' => ApplicationStatus::REJECTED,
                'reject_reason' => $reason,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            (new RecordAuditLog)->handle('application.rejected', $application, null, [
                'reviewed_by' => $actor->getKey(),
                'reason_given' => filled($reason),
            ]);
        });

        $email = data_get($application->payload, 'email');
        if (is_string($email) && $email !== '') {
            $name = trim((string) data_get($application->payload, 'first_name').' '.(string) data_get($application->payload, 'last_name'));
            $applied = data_get($application->payload, 'consent_locale');
            (new QueueClubMail)->handle($email, new ApplicationRejectedMail($name !== '' ? $name : $email, $reason), $application,
                in_array($applied, ['en', 'es'], true) ? $applied : (new ResolveLocale)->handle());
        }

        return $application;
    }
}
