<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Enums\ApplicationStatus;
use App\Models\HeartbeatLog;
use App\Models\MemberApplication;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Scheduled application-retention sweep (closes a security-audit finding on prompt 157). A member APPLICATION is
 * pre-membership Article-9-adjacent data: its `payload` holds the applicant's name, DOB, email and document
 * number, and — since prompt 157 — an OPTIONAL identity photo on the encrypted `documents` disk. An application
 * that was REJECTED or abandoned (invited or submitted but never approved) must not keep that indefinitely; the
 * prompt-157 comment that claimed prompt 142's sweep covered it was wrong (that sweep only prunes member-import
 * CSVs). This ANONYMISES every such application past `application_retention_days` — deletes its vault photo and
 * scrubs the payload + invite email/reference — keeping the row shell (status + timestamps) so the club can
 * still count that an application happened and its outcome, without the personal data (the AnonymiseMember ethos).
 *
 * APPROVED applications are NEVER touched: the approved member SHARES the same photo file (ApproveApplication
 * points `member.photo_path` at `payload['photo_path']`), so deleting it would blank the member's counter photo.
 * Those are governed by the member's own retention (members:purge / AnonymiseMember). Idempotent (an
 * already-scrubbed row is skipped); --dry-run reports without writing; heartbeat so SystemHealth sees it ran.
 */
class PruneApplications extends Command
{
    protected $signature = 'applications:prune-retention {--dry-run : Report what would be anonymised without writing}';

    protected $description = 'Anonymise rejected/abandoned member applications past retention, deleting their ID photo';

    public const ACTION = 'applications.retention.anonymised';

    public const HEARTBEAT = 'application-retention-sweep';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) Settings::get('application_retention_days', 180));

        // Terminal or stale (NOT approved — an approved member owns the shared photo), past retention, and still
        // holding personal data (idempotent — an already-scrubbed row no longer matches).
        $due = fn () => MemberApplication::query()->withoutGlobalScopes()
            ->where('status', '!=', ApplicationStatus::APPROVED->value)
            ->where('updated_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('applicant_email')->orWhereNotNull('payload');
            });

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$due()->count()} application(s) past retention would be anonymised (up to {$cutoff->toDateString()}).");
            HeartbeatLog::beat(self::HEARTBEAT);

            return self::SUCCESS;
        }

        $anonymised = 0;
        $photosDeleted = 0;
        $due()->chunkById(500, function ($rows) use (&$anonymised, &$photosDeleted): void {
            foreach ($rows as $application) {
                $photo = data_get($application->payload, 'photo_path');
                if (is_string($photo) && $photo !== '') {
                    Storage::disk('documents')->delete($photo);
                    $photosDeleted++;
                }

                $application->forceFill([
                    'payload' => null,
                    'applicant_email' => null,
                    'applicant_reference' => null,
                ])->save();
                $anonymised++;
            }
        });

        if ($anonymised > 0) {
            (new RecordAuditLog)->handle(self::ACTION, null, null, [
                'anonymised' => $anonymised,
                'photos_deleted' => $photosDeleted,
                'up_to' => $cutoff->toDateString(),
            ]);
        }

        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info("Anonymised {$anonymised} application(s) past retention ({$photosDeleted} photo(s) deleted, up to {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }
}
