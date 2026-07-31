<?php

namespace Tests\Feature\Security;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Members\ResolveMemberByToken;
use App\Actions\RecordAuditLog;
use App\Enums\SettingType;
use App\Exceptions\ScanRateLimitedException;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 58 — the two loose ends from prompt 17.
 *   1) ResolveMemberByToken (called from Livewire, so route middleware never applies) had NO rate limit;
 *      it now throttles FAILED scans per operator so a scanner can't brute-force card tokens.
 *   2) Audit retention: OPTION B — the audit log is append-only and never purged (the model refuses
 *      deletes), so `audit_retention_days` is a MINIMUM/disclosure figure, not a policy nothing enforces.
 */
class QrScanRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    public function test_repeated_failed_scans_are_rate_limited(): void
    {
        Settings::set('qr_scan_max_failures_per_minute', 3, SettingType::INT);
        $resolver = new ResolveMemberByToken;

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($resolver->handle('bad-token-'.$i, 'op-fail')); // 3 misses allowed
        }

        $this->expectException(ScanRateLimitedException::class);
        $resolver->handle('bad-token-x', 'op-fail'); // the 4th is refused
    }

    public function test_valid_scans_do_not_count_toward_the_limit(): void
    {
        Settings::set('qr_scan_max_failures_per_minute', 2, SettingType::INT);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $token = (new IssueMemberToken)->handle($member);
        $resolver = new ResolveMemberByToken;

        // Only misses count, so a busy counter of valid cards is never throttled.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($member->id, $resolver->handle($token, 'op-valid')?->id);
        }
    }

    public function test_a_scan_without_a_throttle_key_is_never_limited(): void
    {
        $resolver = new ResolveMemberByToken;

        for ($i = 0; $i < 40; $i++) {
            $this->assertNull($resolver->handle('nope-'.$i)); // no key → backward-compatible, unthrottled
        }
    }

    public function test_the_audit_log_is_append_only_so_retention_is_by_construction(): void
    {
        (new RecordAuditLog)->handle('test.retention', null, null, ['x' => 1]);
        $log = AuditLog::query()->withoutGlobalScopes()->latest()->firstOrFail();

        // No purge exists, and none could: deleting an audit row is refused, so the retention the setting
        // advertises is real (append-only), never a claim nothing enforces.
        $this->expectException(RuntimeException::class);
        $log->delete();
    }
}
