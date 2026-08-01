<?php

namespace Tests\Feature\Audit;

use App\Console\Commands\RedactExpiredAuditLogs;
use App\Models\AuditLog;
use App\Support\Settings;
use App\ViewModels\SystemHealth;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 112 — the ROPA declares a 10-year audit retention that nothing applied. A scheduled sweep now
 * REDACTS (not deletes) the payloads of entries past retention, keeping the register inalterable and its
 * shape, while removing the special-category detail. It records its own summary entry and is idempotent.
 */
class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $before */
    private function entry(CarbonInterface $createdAt, array $before = ['field' => 'x'], string $action = 'member.updated'): AuditLog
    {
        $log = AuditLog::factory()->create(['action' => $action, 'before' => $before, 'after' => ['field' => 'y']]);
        $log->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $log->fresh();
    }

    public function test_an_entry_past_retention_is_redacted_and_a_recent_one_is_untouched(): void
    {
        $old = $this->entry(now()->subYears(11));       // > 3650 days
        $recent = $this->entry(now()->subDays(10));

        $this->artisan('audit:redact-retention')->assertSuccessful();

        $this->assertNull($old->fresh()->before);
        $this->assertNull($old->fresh()->after);
        $this->assertNotNull($recent->fresh()->before); // inside the window — identity + payload intact
    }

    public function test_changing_the_setting_moves_the_boundary(): void
    {
        $entry = $this->entry(now()->subDays(100));

        $this->artisan('audit:redact-retention')->assertSuccessful();
        $this->assertNotNull($entry->fresh()->before); // default 3650 days → not yet due

        Settings::set('audit_retention_days', 30);
        $this->artisan('audit:redact-retention')->assertSuccessful();
        $this->assertNull($entry->fresh()->before);     // now past the shortened boundary — the key IS read
    }

    public function test_it_is_idempotent_and_records_one_summary_entry(): void
    {
        $this->entry(now()->subYears(11));

        $this->artisan('audit:redact-retention')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('action', RedactExpiredAuditLogs::ACTION)->count());

        $this->artisan('audit:redact-retention')->assertSuccessful(); // nothing left to redact
        $this->assertSame(1, AuditLog::query()->where('action', RedactExpiredAuditLogs::ACTION)->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $old = $this->entry(now()->subYears(11));

        $this->artisan('audit:redact-retention', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull($old->fresh()->before);
        $this->assertSame(0, AuditLog::query()->where('action', RedactExpiredAuditLogs::ACTION)->count());
    }

    public function test_the_summary_entry_is_exempt_from_the_sweep(): void
    {
        $this->entry(now()->subYears(11));
        $this->artisan('audit:redact-retention')->assertSuccessful();

        $summary = AuditLog::query()->where('action', RedactExpiredAuditLogs::ACTION)->firstOrFail();
        $summary->forceFill(['created_at' => now()->subYears(11)])->saveQuietly(); // age it past retention

        $this->artisan('audit:redact-retention')->assertSuccessful();
        $this->assertNotNull($summary->fresh()->after); // still accounts for the gap — never swept
    }

    public function test_no_user_can_delete_an_audit_entry(): void
    {
        $entry = $this->entry(now());

        $this->expectException(RuntimeException::class);
        $entry->delete(); // append-only — no user-facing deletion path
    }

    public function test_no_user_can_edit_an_audit_entry(): void
    {
        $entry = $this->entry(now());

        $this->expectException(RuntimeException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_system_health_shows_the_sweep_stale_until_it_runs(): void
    {
        $this->assertTrue((new SystemHealth)->auditRetentionSweep()['stale']);

        $this->artisan('audit:redact-retention')->assertSuccessful();

        $this->assertFalse((new SystemHealth)->auditRetentionSweep()['stale']);
    }
}
