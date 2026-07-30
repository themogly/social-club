<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\HeartbeatLog;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\Rat;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityUiTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_the_audit_viewer_is_owner_only_and_never_writable(): void
    {
        // AuditLogPolicy gates the resource on audit.view (owner only) with no create/update/delete.
        $this->assertTrue($this->user(Role::OWNER)->can('viewAny', AuditLog::class));
        $this->assertFalse($this->user(Role::MANAGER)->can('viewAny', AuditLog::class));
        $this->assertFalse($this->user(Role::STAFF)->can('viewAny', AuditLog::class));

        $entry = AuditLog::create(['organisation_id' => $this->org->id, 'action' => 'x']);
        $this->assertFalse($this->user(Role::OWNER)->can('delete', $entry)); // append-only, no delete ability
    }

    public function test_the_rat_marks_cannabis_and_therapeutic_data_as_article_9(): void
    {
        $activities = (new Rat)->activities();

        $this->assertNotEmpty($activities);
        $this->assertTrue(
            collect($activities)->contains(fn (array $a): bool => $a['article_9'] === true),
            'The RAT must flag at least one Article 9 special-category processing activity.'
        );
    }

    public function test_system_health_flags_a_stale_scheduler_and_keeps_audit_retention_longer(): void
    {
        $health = new SystemHealth;

        // No heartbeat yet → stale (the failure mode of a dead cron is silence).
        $this->assertTrue($health->scheduler()['stale']);

        HeartbeatLog::beat('scheduler');
        $this->assertFalse((new SystemHealth)->scheduler()['stale']);

        // Audit entries are deliberately retained LONGER than member data.
        $this->assertGreaterThan($health->dataRetentionDays(), $health->auditRetentionDays());
    }
}
