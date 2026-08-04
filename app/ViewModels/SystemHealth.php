<?php

namespace App\ViewModels;

use App\Models\HeartbeatLog;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * The operational health snapshot — because the failure mode of a broken cron or queue is
 * SILENCE. It reads the scheduler heartbeat (age vs a stale threshold), the queue depth and
 * dead-letter count, and surfaces backup/restore placeholders. Every figure is live-queried;
 * none of this is cached (it is exactly the transactional state you must not stale-cache).
 */
class SystemHealth
{
    /** Default staleness window if unset — the heartbeat runs every 5 min, so 15 min = 3 missed. */
    public const DEFAULT_STALE_SECONDS = 900;

    /** A daily job (05:00) is stale if unseen within ~26h — one missed run plus grace. */
    public const DAILY_STALE_SECONDS = 93600;

    /** An hourly job is stale if unseen within ~2h — one missed run plus grace. */
    public const HOURLY_STALE_SECONDS = 7200;

    /**
     * The generic scheduler heartbeat (system:heartbeat every 5 min) — proves the cron is alive.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function scheduler(): array
    {
        return $this->component('scheduler', (int) Settings::get('heartbeat_stale_seconds', self::DEFAULT_STALE_SECONDS));
    }

    /**
     * Transports that cannot send without an API credential → the config key that must be non-empty.
     * (log / array / smtp need no API key here, so they are never alarmed on.)
     *
     * @var array<string, string>
     */
    private const MAILER_CREDENTIALS = [
        'resend' => 'services.resend.key',
        'ses' => 'services.ses.key',
        'postmark' => 'services.postmark.token',
    ];

    /**
     * The configured mail transport and whether its required credential is present (prompt 145). A missing key
     * is otherwise SILENT — mail simply never arrives — so a mailer that needs a credential and lacks one is
     * surfaced here, in the same family as the scheduler/queue checks. This is a CONFIGURATION check only: it
     * never sends a probe email (that would spend real quota and put a network call inside a health panel).
     *
     * @return array{mailer: string, needs_credential: bool, configured: bool}
     */
    public function mailer(): array
    {
        $mailer = (string) config('mail.default');
        $key = self::MAILER_CREDENTIALS[$mailer] ?? null;

        if ($key === null) {
            return ['mailer' => $mailer, 'needs_credential' => false, 'configured' => true];
        }

        return ['mailer' => $mailer, 'needs_credential' => true, 'configured' => filled(config($key))];
    }

    /**
     * Filesystem drivers whose Flysystem adapter ships as a SEPARATE Composer package → the adapter class name
     * that must be present. `local` needs none. If the class is absent, the disk cannot be constructed. (Plain
     * strings, not class-strings: the sftp adapter is intentionally not installed, so it is not a resolvable
     * class symbol — checked with class_exists() either way.)
     *
     * @var array<string, string>
     */
    private const DOCUMENTS_ADAPTERS = [
        's3' => AwsS3V3Adapter::class,
        // String literal, not ::class — this adapter's package is intentionally NOT installed, so a `::class`
        // reference would be an unresolvable symbol; it is the genuinely-absent driver the health check catches.
        'sftp' => 'League\\Flysystem\\PhpseclibV3\\SftpAdapter',
    ];

    /**
     * The configured `documents` disk driver and whether its Flysystem adapter is available (prompt 145).
     * `DOCUMENTS_DRIVER=s3` with `league/flysystem-aws-s3-v3` absent throws `Class "…AwsS3V3…" not found` the
     * first time a member ID scan / medical certificate is written — the Article 9 object-storage path. This is
     * a CONFIGURATION check only: it confirms the driver's adapter class exists, never writes a probe object.
     *
     * @return array{driver: string, available: bool}
     */
    public function documentsDisk(): array
    {
        $driver = (string) config('filesystems.disks.documents.driver', 'local');
        $adapterClass = self::DOCUMENTS_ADAPTERS[$driver] ?? null;

        return ['driver' => $driver, 'available' => $adapterClass === null || class_exists($adapterClass)];
    }

    /**
     * The nightly membership expiry sweep SPECIFICALLY (memberships:sweep). It stamps its own
     * heartbeat on success, so this goes stale — red — if the sweep silently stops running even
     * while the generic scheduler heartbeat above stays fresh. That gap is the whole point.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function expirySweep(): array
    {
        return $this->component('memberships-sweep', self::DAILY_STALE_SECONDS);
    }

    /**
     * The temporary-member auto-removal sweep (members:remove-temporary). Same per-job
     * heartbeat discipline as the expiry sweep — only surfaced when the feature is on.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function temporarySweep(): array
    {
        return $this->component('temporary-sweep', self::DAILY_STALE_SECONDS);
    }

    /**
     * The audit-log retention sweep (audit:redact-retention). Goes stale — red — if it stops running, so a
     * declared retention period is proven to be APPLIED, not just configured (prompt 112).
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function auditRetentionSweep(): array
    {
        return $this->component('audit-retention-sweep', self::DAILY_STALE_SECONDS);
    }

    /**
     * The message retention sweep (messages:prune-retention). Goes stale — red — if it stops running, so the
     * declared message retention is proven APPLIED, not just configured (prompt 136).
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function messageRetentionSweep(): array
    {
        return $this->component('message-retention-sweep', self::DAILY_STALE_SECONDS);
    }

    /**
     * The member-import staging sweep (imports:prune-staging). Goes stale — red — if it stops running, so the
     * declared retention of that plaintext-register scratch space is proven APPLIED, not just configured
     * (prompt 142). Hourly job, so a tighter stale window than the daily sweeps.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function importStagingSweep(): array
    {
        return $this->component('import-staging-sweep', self::HOURLY_STALE_SECONDS);
    }

    /**
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    private function component(string $component, int $threshold): array
    {
        $last = HeartbeatLog::query()->component($component)->latest('ran_at')->first();
        $ranAt = $last?->ran_at;
        $age = $ranAt !== null ? max(0, now()->getTimestamp() - $ranAt->getTimestamp()) : null;

        return [
            'last_at' => $ranAt,
            'age_seconds' => $age,
            'stale' => $age === null || $age > $threshold,
            'threshold_seconds' => $threshold,
        ];
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function queue(): array
    {
        return [
            'pending' => (int) DB::table('jobs')->count(),
            'failed' => (int) DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * The DEFAULT cache store's reachability (prompt 124) — usually Redis. A monitoring page that dies with the
     * thing it monitors is not monitoring, so this does a trivial round-trip and reports whether it worked,
     * NEVER throwing. When it is unreachable, authorisation still runs (the permission cache lives on the
     * `database` store), but the queue is stopped and cached reads fall back to a query — so the page shows it
     * as degraded rather than failing.
     *
     * @return array{store: string, reachable: bool}
     */
    public function cache(): array
    {
        $store = (string) config('cache.default');

        try {
            Cache::store($store)->put('csc.health.probe', 1, 10);
            $reachable = Cache::store($store)->get('csc.health.probe') === 1;
        } catch (\Throwable) {
            $reachable = false;
        }

        return ['store' => $store, 'reachable' => $reachable];
    }

    /**
     * Last backup / restore. No backup system is wired in-app, so these are placeholders
     * (Settings keys that stay null until a backup pipeline writes them).
     *
     * @return array{last_backup: ?string, last_restore: ?string}
     */
    public function backups(): array
    {
        $backup = Settings::get('last_backup_at');
        $restore = Settings::get('last_restore_at');

        return [
            'last_backup' => is_string($backup) ? $backup : null,
            'last_restore' => is_string($restore) ? $restore : null,
        ];
    }

    public function auditRetentionDays(): int
    {
        return (int) Settings::get('audit_retention_days', 3650);
    }

    public function dataRetentionDays(): int
    {
        return (int) Settings::get('data_retention_days', 1825);
    }
}
