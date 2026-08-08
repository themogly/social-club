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
 * number, and — since prompts 157 and 178 — an OPTIONAL face photo, an OPTIONAL identity document AND (prompt 220) the applicant's drawn signature, all on the encrypted `documents` disk. An application
 * that was REJECTED or abandoned (invited or submitted but never approved) must not keep that indefinitely; the
 * prompt-157 comment that claimed prompt 142's sweep covered it was wrong (that sweep only prunes member-import
 * CSVs). This ANONYMISES every such application past `application_retention_days` — deletes BOTH vault files and
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
                // `applicant_reference` counts as personal data here (security audit, Phase C carry-forward).
                // A `handover`-mode invite sets NEITHER an email NOR a payload — it is printed and handed
                // over — so without this clause it never matched, and the reference survived indefinitely.
                // It is a person: the field is labelled "¿Para quién es la invitación?" with the helper text
                // "Un nombre o referencia (p. ej. el avalador)". The sweep already nulls it; it simply never
                // ran for these rows.
                $query->whereNotNull('applicant_email')
                    ->orWhereNotNull('payload')
                    ->orWhereNotNull('applicant_reference');
            });

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$due()->count()} application(s) past retention would be anonymised (up to {$cutoff->toDateString()}).");
            HeartbeatLog::beat(self::HEARTBEAT);

            return self::SUCCESS;
        }

        $anonymised = 0;
        $photosDeleted = 0;
        $scansDeleted = 0;
        $signaturesDeleted = 0;
        $due()->chunkById(500, function ($rows) use (&$anonymised, &$photosDeleted, &$scansDeleted, &$signaturesDeleted): void {
            foreach ($rows as $application) {
                $photo = data_get($application->payload, 'photo_path');
                if (is_string($photo) && $photo !== '') {
                    Storage::disk('documents')->delete($photo);
                    $photosDeleted++;
                }

                // The identity DOCUMENT (prompt 178) goes the same way as the photo, and counted separately —
                // a silent deletion of Article 9 material is as bad as an indefinite retention of it, so the
                // audit log has to say how many of WHICH artefact went. Same APPROVED carve-out applies: an
                // approved member points at this same file, and approved rows never reach this loop.
                $scan = data_get($application->payload, 'document_scan_path');
                if (is_string($scan) && $scan !== '') {
                    Storage::disk('documents')->delete($scan);
                    $scansDeleted++;
                }

                // And the applicant's drawn signature (prompt 220), counted as its own artefact for the same
                // reason: it is the person's hand over a consent text that this row is about to stop
                // evidencing. Never reached for an APPROVED application — the member's consent record points
                // at this same file, and approved rows are excluded above.
                $signature = data_get($application->payload, 'signature_path');
                if (is_string($signature) && $signature !== '') {
                    Storage::disk('documents')->delete($signature);
                    $signaturesDeleted++;
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
                'id_scans_deleted' => $scansDeleted,
                'signatures_deleted' => $signaturesDeleted,
                'up_to' => $cutoff->toDateString(),
            ]);
        }

        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info("Anonymised {$anonymised} application(s) past retention ({$photosDeleted} photo(s), {$scansDeleted} ID scan(s), {$signaturesDeleted} signature(s) deleted, up to {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }
}
