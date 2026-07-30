<?php

namespace Tests\Feature\Schema;

use App\Models\AuditLog;
use App\Models\Minute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_cannot_be_updated(): void
    {
        $log = AuditLog::factory()->create(['action' => 'member.created']);

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);
        $log->delete();
    }

    public function test_a_signed_minute_cannot_be_updated(): void
    {
        $minute = Minute::factory()->create(['signed_at' => now()]);

        $this->expectException(RuntimeException::class);
        $minute->update(['body' => 'altered after signing']);
    }

    public function test_a_signed_minute_cannot_be_deleted(): void
    {
        $minute = Minute::factory()->create(['signed_at' => now()]);

        $this->expectException(RuntimeException::class);
        $minute->delete();
    }

    public function test_an_unsigned_minute_can_still_be_edited_and_then_signed(): void
    {
        $minute = Minute::factory()->create(['signed_at' => null]);

        $minute->update(['body' => 'draft edit']);       // allowed
        $minute->update(['signed_at' => now()]);          // signing is allowed

        $this->assertNotNull($minute->fresh()->signed_at);
    }
}
