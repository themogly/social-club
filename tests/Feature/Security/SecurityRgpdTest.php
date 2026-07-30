<?php

namespace Tests\Feature\Security;

use App\Actions\Members\AnonymiseMember;
use App\Enums\DispensationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Minute;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class SecurityRgpdTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_the_audit_log_cannot_be_updated_or_deleted(): void
    {
        $entry = AuditLog::create(['organisation_id' => $this->org->id, 'action' => 'test.event']);

        try {
            $entry->update(['action' => 'tampered']);
            $this->fail('Audit entries must be immutable.');
        } catch (RuntimeException) {
        }
        try {
            $entry->fresh()->delete();
            $this->fail('Audit entries must not be deletable.');
        } catch (RuntimeException) {
        }

        // The entry survived both attempts, unchanged and still present.
        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'action' => 'test.event']);
    }

    public function test_erasure_anonymises_the_member_but_leaves_every_total_identical(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Lucía', 'last_name' => 'Fernández',
            'email' => 'lucia@example.es', 'status' => MemberStatus::INACTIVE,
        ]);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $d = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => 8500, 'cash_cents' => 8500, 'wallet_cents' => 0,
        ]);
        DispensationLine::factory()->create(['dispensation_id' => $d->id, 'genetic_id' => $genetic->id, 'grams_cg' => 350]);
        WalletTransaction::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'amount_cents' => 5000, 'type' => WalletTransactionType::TOPUP, 'balance_after_cents' => 5000,
        ]);

        $contributionsBefore = (int) Dispensation::query()->withoutGlobalScopes()->where('member_id', $member->id)->sum('total_cents');
        $gramsBefore = (int) DispensationLine::query()->withoutGlobalScopes()->whereHas('dispensation', fn ($q) => $q->where('member_id', $member->id))->sum('grams_cg');
        $walletBefore = (int) WalletTransaction::query()->withoutGlobalScopes()->where('member_id', $member->id)->sum('amount_cents');

        (new AnonymiseMember)->handle($member);
        $fresh = $member->fresh();

        // Personal data scrubbed…
        $this->assertSame('ANONIMIZADO', $fresh->first_name);
        $this->assertNull($fresh->email);
        $this->assertNotNull($fresh->anonymised_at);

        // …but the books still balance to the cent, attributed to the same record.
        $this->assertSame($contributionsBefore, (int) Dispensation::query()->withoutGlobalScopes()->where('member_id', $member->id)->sum('total_cents'));
        $this->assertSame($gramsBefore, (int) DispensationLine::query()->withoutGlobalScopes()->whereHas('dispensation', fn ($q) => $q->where('member_id', $member->id))->sum('grams_cg'));
        $this->assertSame($walletBefore, (int) WalletTransaction::query()->withoutGlobalScopes()->where('member_id', $member->id)->sum('amount_cents'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.anonymised']);
    }

    public function test_the_retention_purge_has_a_dry_run_and_is_idempotent(): void
    {
        // A member who left well past the retention window (default 1825 days).
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::INACTIVE,
            'left_at' => now()->subDays(2000), 'anonymised_at' => null,
        ]);

        // Dry-run writes nothing.
        Artisan::call('members:purge', ['--dry-run' => true]);
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->whereNotNull('anonymised_at')->count());

        // Real run anonymises exactly one; a second run is a no-op (idempotent).
        Artisan::call('members:purge');
        $this->assertSame(1, Member::query()->withoutGlobalScopes()->whereNotNull('anonymised_at')->count());
        Artisan::call('members:purge');
        $this->assertSame(1, Member::query()->withoutGlobalScopes()->whereNotNull('anonymised_at')->count());
    }

    public function test_no_user_addressable_model_uses_a_sequential_key(): void
    {
        // The IDOR guard: every user-addressable model is ULID-keyed, never auto-increment.
        $models = [
            Member::class, Dispensation::class, Order::class, Batch::class, MemberDocument::class,
            Minute::class, TillSession::class, WalletTransaction::class, AuditLog::class,
        ];
        foreach ($models as $class) {
            /** @var Model $model */
            $model = new $class;
            $this->assertSame('string', $model->getKeyType(), "{$class} must use ULID keys");
            $this->assertFalse($model->getIncrementing(), "{$class} must not auto-increment");
        }
    }

    public function test_no_registered_route_exposes_a_bare_numeric_id_segment(): void
    {
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            foreach (explode('/', $route->uri()) as $segment) {
                $this->assertFalse(
                    ctype_digit($segment) && (int) $segment > 0,
                    "Route [{$route->uri()}] exposes a numeric id segment"
                );
            }
        }
    }

    public function test_the_magic_link_endpoint_is_rate_limited(): void
    {
        // Rate limiting on the passwordless login request (login / magic-link surface).
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('socio.login.send'), ['email' => 'x@example.es'])->assertStatus(302);
        }
        $this->post(route('socio.login.send'), ['email' => 'x@example.es'])->assertStatus(429);
    }

    public function test_a_staff_user_is_denied_owner_only_surfaces(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        // Staff hold none of the org-wide/system permissions.
        $this->assertFalse($staff->can('audit.view'));
        $this->assertFalse($staff->can('data.erase'));
        $this->assertFalse($staff->can('staff.manage'));
        $this->assertFalse($staff->can('settings.manage'));
    }
}
