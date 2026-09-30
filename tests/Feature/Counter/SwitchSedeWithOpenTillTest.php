<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Actions\Till\SelectTillSession;
use App\Enums\CashMovementType;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Filament\Pages\RolesPermissions;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\Support\Permissions;
use App\ViewModels\Dashboard;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 335 — Ben: "I want to be able to change location if I need to, if I'm admin." Changing sede with the current
 * sede's till open was refused outright ("Cierra la caja de esta sede antes de cambiar."). With
 * `counter.switch_with_open_till` (OWNER + MANAGER by default) the counter now asks first and, confirmed, switches —
 * leaving that till exactly as it was. The tills stay strictly per sede.
 */
class SwitchSedeWithOpenTillTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green Greenhouse']);
    }

    private function at(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($user);
        CounterOperator::set($user);
        session(['counter.location_id' => $this->centro->id]);

        return $user;
    }

    private function openTillAtCentro(User $by): TillSession
    {
        $till = (new OpenTill)->handle($this->centro, 'POS-1', 10000, ['operator_id' => $by->id]);
        (new RecordCashMovement)->handle($till, CashMovementType::IN, 500, ['operator_id' => $by->id, 'reason' => 'Cambio']);

        return $till;
    }

    private function switchTo(Location $sede, bool $confirmed = false)
    {
        return $this->from(route('counter.home'))->post(route('counter.location'), array_filter([
            'location_id' => $sede->id, 'confirm_open_till' => $confirmed ? '1' : null,
        ]));
    }

    // --- 1. With the permission: asked, then switched, the till untouched ------------------------------------------------

    public function test_a_manager_is_asked_and_confirming_switches_leaving_the_till_exactly_as_it_was(): void
    {
        $manager = $this->at(Role::MANAGER);
        $till = $this->openTillAtCentro($manager);
        $before = [$till->fresh()->status, $till->fresh()->float_cents->cents, $till->cashMovements()->count(), $till->fresh()->opened_by];

        // Asked first; cancelling is simply not confirming — nothing has changed.
        $this->switchTo($this->norte)->assertRedirect(route('counter.home'))->assertSessionHas('counterSedeSwitchConfirm')->assertSessionMissing('counterLocationError');
        $this->assertSame($this->centro->id, session('counter.location_id'));
        $this->get(route('counter.home'))->assertSee(__('La caja de :sede sigue abierta.', ['sede' => 'Dream Green']))
            ->assertSee(__('Se quedará abierta; podrás volver a cerrarla más tarde. ¿Cambiar a :sede?', ['sede' => 'Dream Green Greenhouse']))
            ->assertSee(__('Cambiar de sede'))->assertSee(__('Cancelar'));
        $this->assertSame(0, AuditLog::query()->where('action', 'counter.sede.switched_with_open_till')->count());

        $this->switchTo($this->norte, confirmed: true)->assertRedirect(route('counter.home'));
        $this->assertSame($this->norte->id, session('counter.location_id'));
        $this->assertSame($before, [$till->fresh()->status, $till->fresh()->float_cents->cents, $till->cashMovements()->count(), $till->fresh()->opened_by]);
        $this->assertSame(TillSessionStatus::OPEN, $till->fresh()->status);

        $audit = AuditLog::query()->where('action', 'counter.sede.switched_with_open_till')->sole();
        $this->assertSame([$this->centro->id, $this->norte->id, $till->id, $manager->id],
            [$audit->after['from_location_id'] ?? null, $audit->after['to_location_id'] ?? null, $audit->after['till_session_id'] ?? null, $audit->after['operator_id'] ?? null]);
    }

    public function test_after_the_switch_the_new_sede_has_no_till_and_the_old_one_is_never_used(): void
    {
        $manager = $this->at(Role::MANAGER);
        $this->openTillAtCentro($manager);
        $this->switchTo($this->norte, confirmed: true);

        $this->assertNull((new SelectTillSession)->handle($this->norte, 'POS-1'), 'the old till answered for the new sede');
        $this->get(route('counter.pos'))->assertRedirect(); // 236 — to the new sede's «Abrir caja»
        $this->assertSame($this->centro->id, TillSession::query()->withoutGlobalScopes()->where('status', TillSessionStatus::OPEN->value)->sole()->location_id);
    }

    // --- 3. Without the permission ---------------------------------------------------------------------------------------------

    public function test_without_the_permission_the_switch_is_refused_as_today(): void
    {
        $this->setRolePermission(Role::MANAGER, 'counter.switch_with_open_till', false);
        $manager = $this->at(Role::MANAGER);
        CounterOperator::set($manager->fresh());
        $this->openTillAtCentro($manager);

        $this->switchTo($this->norte)->assertSessionHas('counterLocationError', __('Cierra la caja de esta sede antes de cambiar.'))->assertSessionMissing('counterSedeSwitchConfirm');
        $this->switchTo($this->norte, confirmed: true)->assertSessionHas('counterLocationError', __('Cierra la caja de esta sede antes de cambiar.'));
        $this->assertSame($this->centro->id, session('counter.location_id'));

        $this->at(Role::STAFF);
        $this->switchTo($this->norte, confirmed: true)->assertSessionHas('counterLocationError', __('La sede la cambia un responsable'));
        $this->assertSame($this->centro->id, session('counter.location_id'));
    }

    // --- 4. Coming back ------------------------------------------------------------------------------------------------------------

    public function test_coming_back_finds_the_till_open_and_it_closes_normally(): void
    {
        $manager = $this->at(Role::MANAGER);
        $till = $this->openTillAtCentro($manager);
        $this->switchTo($this->norte, confirmed: true);

        $this->switchTo($this->centro)->assertSessionMissing('counterSedeSwitchConfirm'); // Norte has no open till: a plain switch
        $this->assertSame($this->centro->id, session('counter.location_id'));
        $this->assertSame($till->id, (new SelectTillSession)->handle($this->centro, 'POS-1')?->id);
        // Still visible while away: its own sede's hub alert, and the owner's rollup across sedes.
        $this->get(route('counter.home'))->assertSee(trans_choice(':count caja sin cerrar|:count cajas sin cerrar', 1, ['count' => 1]));
        $this->assertTrue((new Dashboard($this->org->id, null, Period::today()))->hasUnreconciledTill());

        (new CloseTill)->handle($till->fresh(), 10500, $manager);
        $this->assertSame(TillSessionStatus::CLOSED, $till->fresh()->status);
    }

    // --- 5. The permission --------------------------------------------------------------------------------------------------------

    public function test_the_permission_is_owners_and_managers_and_needs_the_sede_permission(): void
    {
        $this->assertContains('counter.switch_with_open_till', Permissions::for(Role::OWNER));
        $this->assertContains('counter.switch_with_open_till', Permissions::for(Role::MANAGER));
        $this->assertNotContains('counter.switch_with_open_till', Permissions::for(Role::STAFF));
        $this->assertSame(['settings.manage.location'], Permissions::DEPENDENCIES['counter.switch_with_open_till'] ?? null);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(RolesPermissions::class)->assertSee(__('Cambiar de sede con la caja abierta'));
    }
}
