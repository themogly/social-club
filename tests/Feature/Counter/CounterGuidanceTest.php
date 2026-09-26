<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Filament\Pages\RolesPermissions;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 265 §2–3 — the till and limit screens tell staff what to do.
 *
 * §2: granting STAFF "Cerrar caja" alone left them stuck at the end-of-day close — "Primero hay que recontar la flor"
 * then "No tienes permiso para recontar el inventario". The roles page now warns about a grant that needs another.
 * §3: a staff member at a limit breach saw "(limits.override)" and no way forward. The copy is plain words, and a
 * manager can authorise with THEIR PIN without taking over the counter.
 */
class CounterGuidanceTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $staff;

    private User $manager;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $this->staff = $this->user(Role::STAFF, '1111', 'Sara Personal');
        $this->manager = $this->user(Role::MANAGER, '2222', 'Marta Encargada');
        $this->owner = $this->user(Role::OWNER, null, 'Dueño');
        $this->actingAs($this->staff);
        CounterOperator::set($this->staff);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function user(Role $role, ?string $pin, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function genetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);

        return $genetic;
    }

    // --- §2. The dependency warning -----------------------------------------------------------------------------

    public function test_the_roles_page_warns_when_close_till_is_granted_without_the_recount(): void
    {
        $this->setRolePermission(Role::STAFF, 'till.close', true);

        $html = Livewire::actingAs($this->owner)->test(RolesPermissions::class)->html();
        $this->assertStringContainsString('data-dependency-warning="'.Role::STAFF->value.':till.close"', $html);
        $this->assertStringContainsString(e(__('Para cerrar la caja al final del día también hace falta «:needs».', ['needs' => Permissions::label('stock.take')])), $html);

        // One click grants the missing one; the warning goes.
        $html = Livewire::actingAs($this->owner)->test(RolesPermissions::class)
            ->call('grantDependency', Role::STAFF->value, 'stock.take')
            ->html();
        $this->assertTrue($this->staff->fresh()->can('stock.take'));
        $this->assertStringNotContainsString('data-dependency-warning="'.Role::STAFF->value.':till.close"', $html);
    }

    public function test_the_end_of_day_close_needs_both_permissions(): void
    {
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic()->id, 'location_id' => $this->location->id,
            'initial_cg' => 5000, 'remaining_cg' => 4000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]); // touched today → the recount is required before the last close

        // Only "Cerrar caja": stuck at the recount.
        $this->setRolePermission(Role::STAFF, 'till.close', true);
        Livewire::test(TillSession::class)
            ->call('startClose')->set('countInput', '100')->call('submitCount')
            ->assertSet('flashMessage', __('Primero hay que recontar la flor.'))
            ->set("reweighCounts.{$batch->id}", '40')->call('submitReweigh')
            ->assertSet('flashMessage', __('No tienes permiso para recontar el inventario.'));
        $this->assertSame(TillSessionStatus::OPEN, TillSessionModel::query()->withoutGlobalScopes()->sole()->status);

        // With the recount as well: it closes.
        $this->setRolePermission(Role::STAFF, 'stock.take', true);
        Livewire::test(TillSession::class)
            ->call('startClose')
            ->set("reweighCounts.{$batch->id}", '40')->call('submitReweigh')
            ->set('countInput', '100')->call('submitCount')
            ->assertSet('countSubmitted', true);
        $this->assertSame(TillSessionStatus::CLOSED, TillSessionModel::query()->withoutGlobalScopes()->sole()->status);
    }

    // --- §3. No raw permission keys in counter copy ---------------------------------------------------------------

    public function test_no_counter_copy_shows_a_permission_key(): void
    {
        $files = array_merge(
            glob(resource_path('views/livewire/counter/**/*.blade.php')) ?: [],
            glob(resource_path('views/livewire/counter/*.blade.php')) ?: [],
            glob(resource_path('views/components/counter/*.blade.php')) ?: [],
            glob(app_path('Livewire/Counter/*.php')) ?: [],
            glob(app_path('Livewire/Counter/Concerns/*.php')) ?: [],
        );

        $offenders = [];
        foreach (array_unique($files) as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $copy) {
                foreach (Permissions::ALL as $key) {
                    if (preg_match('/(?<![\w.])'.preg_quote($key, '/').'(?![\w.])/', $copy)) {
                        $offenders[] = basename($file).': '.$copy;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'A counter operator is shown a raw permission key.');
    }

    // --- §3. The supervisor PIN ------------------------------------------------------------------------------------

    private function overLimitVisit(): Testable
    {
        $genetic = $this->genetic();
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100, 'monthly_limit_cg' => 100000, // 1 g a day
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3')->call('addLine')
            ->call('commitDispensation')
            ->assertSet('requireOverride', true);
    }

    public function test_the_breach_panel_speaks_plainly_and_offers_a_supervisor_pin(): void
    {
        $html = $this->overLimitVisit()->html();

        $this->assertStringContainsString(e(__('Hace falta que un encargado autorice esta excepción.')), $html);
        $this->assertStringContainsString('data-authorise-with-pin', $html);
        $this->assertStringNotContainsString('limits.override', $html);
    }

    public function test_a_manager_pin_authorises_the_override_without_taking_over_the_counter(): void
    {
        $this->overLimitVisit()
            ->set('authoriserPin', '2222')
            ->set('overrideReason', 'Socio terapéutico, lo autorizo')
            ->call('commitWithAuthoriserPin')
            ->assertSet('flashType', 'success');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->staff->id, $dispensation->operator_id, 'the operator of record must stay the staff member');
        $override = AuditLog::query()->where('action', 'dispensation.limit.override')->sole();
        $this->assertSame($this->manager->id, $override->after['authorised_by'], 'the override must be the manager\'s');
        $this->assertSame('Socio terapéutico, lo autorizo', $override->after['reason']);
        $this->assertSame($this->staff->id, CounterOperator::id(), 'the staff member was signed out');
    }

    public function test_a_pin_without_the_permission_is_refused_and_wrong_pins_count(): void
    {
        $visit = $this->overLimitVisit();

        // Another STAFF member's PIN: a real PIN, but no limits.override.
        $this->user(Role::STAFF, '3333', 'Otro Personal');
        $visit->set('authoriserPin', '3333')->set('overrideReason', 'x')->call('commitWithAuthoriserPin')
            ->assertSet('flashType', 'error');

        foreach (range(1, 3) as $i) {
            $visit->set('authoriserPin', '9999')->set('overrideReason', 'x')->call('commitWithAuthoriserPin');
        }

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame($this->staff->id, CounterOperator::id());
        $status = (new UnlockOperator)->statusFor('counter-pin:'.$this->location->id);
        $this->assertTrue($status['attempts'] > 0 || $status['locked'], 'wrong PINs did not count towards the PIN throttle');
    }
}
