<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Models\HeartbeatLog;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Scheduled sweep of the member-import staging directory (prompt 142, following the retention pattern of
 * prompt 112). `ListMembers::importAction()` copies the uploaded CSV to storage/app/member-imports/{ulid}.csv
 * so it survives from the preview request into the confirm request; `resetImport()` deletes it on commit, on
 * cancel, and when the stash is missing — but NOT when the operator simply walks away (closes the tab). That
 * file is the club's entire paper register in plaintext (names, emails, DOBs, document numbers), so leaving it
 * forever is undeclared, unbounded retention of personal data.
 *
 * This is the GUARANTEE (the sweep), exactly as the unique index — not the check-then-insert — is what makes
 * idempotency hold elsewhere. It deletes staged files older than the window, and ONLY those: a stash for an
 * import currently mid-flow was just written, so it is newer than the cutoff and is left untouched. Idempotent
 * (a second run finds nothing), safe when the directory is empty or absent, heartbeat stamped so a silently
 * stopped sweep goes red on Salud del sistema.
 */
class PruneImportStaging extends Command
{
    protected $signature = 'imports:prune-staging {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete abandoned member-import staging CSVs past the retention window';

    public const ACTION = 'import.staging.pruned';

    public const HEARTBEAT = 'import-staging-sweep';

    public function handle(): int
    {
        $dir = storage_path('app/member-imports');
        $hours = max(1, (int) Settings::get('import_staging_retention_hours', 4));
        $cutoff = now()->subHours($hours)->getTimestamp();

        // Safe when the directory is absent or empty — nothing to sweep.
        $files = is_dir($dir) ? (glob($dir.DIRECTORY_SEPARATOR.'*.csv') ?: []) : [];

        $deleted = 0;
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            // Only files OLDER than the window — a stash for an import mid-flow (just written) is newer than the
            // cutoff and must not be deleted out from under the operator.
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }
            if ($this->option('dry-run')) {
                $deleted++;

                continue;
            }
            if (@unlink($file)) {
                $deleted++;
            }
        }

        if (! $this->option('dry-run') && $deleted > 0) {
            (new RecordAuditLog)->handle(self::ACTION, null, null, ['deleted' => $deleted, 'older_than_hours' => $hours]);
        }

        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."Swept {$deleted} abandoned import staging file(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
