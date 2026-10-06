<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Models\HeartbeatLog;
use App\Models\MemberApplication;
use App\Support\DocumentVault;
use App\Support\KeptUploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Prompt 361 — the backstop for files kept from a refused application submit ({@see KeptUploads}): anything older than 24
 * hours, and everything of an application whose invitation is no longer live (expired, revoked, decided), is deleted.
 * Unsubmitted ID scans must never linger (the member-import staging rule is the precedent). Idempotent; heartbeat for
 * *Salud del sistema*.
 */
class PurgeKeptUploads extends Command
{
    protected $signature = 'applications:purge-kept-uploads';

    protected $description = 'Delete photos and ID scans kept from refused application submits (older than 24 h, or of a dead invitation)';

    public const HEARTBEAT = 'kept-uploads-sweep';

    public const HOURS = 24;

    public function handle(): int
    {
        $disk = Storage::disk(DocumentVault::DISK);
        $cutoff = now()->subHours(self::HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->directories(KeptUploads::DIRECTORY) as $applicationDir) {
            $application = MemberApplication::query()->withoutGlobalScopes()->find(basename($applicationDir));
            $dead = $application === null || ! $application->isInviteLive() || $application->submitted_at !== null;

            foreach ($disk->allFiles($applicationDir) as $file) {
                if ($dead || $disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                    $deleted++;
                }
            }
            if ($disk->allFiles($applicationDir) === []) {
                $disk->deleteDirectory($applicationDir);
            }
        }

        if ($deleted > 0) {
            (new RecordAuditLog)->handle('application.kept_uploads.purged', null, null, ['deleted' => $deleted]);
        }
        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info("Purged {$deleted} kept upload(s).");

        return self::SUCCESS;
    }
}
