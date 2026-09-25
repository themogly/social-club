<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 239 — a shift change is a PIN, not a login.
 *
 * A counter tablet is signed in ONCE by a responsable and shared all evening; floor staff identify by PIN.
 * Two consequences the counter got wrong:
 *
 *   · An operator ending their turn reached for "Cerrar sesión" — which ended the DEVICE session and dropped
 *     them at an email/password form they have no credentials for. Their real action is "Cambiar de persona":
 *     clear the PIN, next person enters theirs. The device logout is a responsable's act, gated on staff.manage.
 *   · When the session lifetime lapsed, the device fell to the login form. Remember-me (checked by default)
 *     re-authenticates it, so it lands on the counter's PIN surface instead.
 */
class ShiftChangeIsAPinTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        Filament::setCurrentPanel('admin');
    }

    /** A PIN-identified operator at a sede with an open till (the counter needs one to render — prompt 236). */
    private function operatorAtCounter(Role $role): User
    {
        $user = User::factory()->create(['name' => $role === Role::OWNER ? 'Olivia Owner' : 'Sara Staff']);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
        session(['counter.location_id' => $this->location->id]);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    private function counterHtml(): string
    {
        return (string) $this->withSession([
            'counter.location_id' => $this->location->id,
            'counter.operator_id' => CounterOperator::id(),
        ])->get(route('counter.checkin'))->assertOk()->getContent();
    }

    // --- Remember-me lands session expiry on the PIN surface ----------------------

    public function test_the_login_remembers_the_device_by_default(): void
    {
        // A guest reaches the login form; "remember me" is pre-checked so a shared tablet survives session
        // expiry and returns to the PIN surface, not this form.
        Livewire::test(Login::class)->assertFormSet(['remember' => true]);
    }

    // --- The operator's control is "Cambiar de persona", on every role ------------

    public function test_the_operator_control_is_a_person_switch_not_a_logout(): void
    {
        $this->operatorAtCounter(Role::STAFF);
        $html = $this->counterHtml();

        // The switch chip is the operator's shift-end: hand over, next PIN. Present, and named for that.
        $this->assertStringContainsString('data-counter-switch-operator', $html);
        $this->assertStringContainsString(__('Cambiar de persona'), $html);
    }

    // --- The device logout is a responsable's, gated and confirmed ----------------

    public function test_floor_staff_never_see_the_device_logout(): void
    {
        $this->operatorAtCounter(Role::STAFF); // STAFF holds no staff.manage
        $html = $this->counterHtml();

        $this->assertStringNotContainsString('data-counter-logout', $html, 'floor staff must not be able to end the device session');
        $this->assertStringNotContainsString(__('Cerrar sesión del dispositivo'), $html);
    }

    public function test_a_responsable_gets_the_device_logout_gated_and_confirmed(): void
    {
        $this->operatorAtCounter(Role::OWNER); // OWNER holds staff.manage
        $html = $this->counterHtml();

        $this->assertStringContainsString('data-counter-logout', $html);
        $this->assertStringContainsString(__('Cerrar sesión del dispositivo'), $html);
        // It asks first — always, because it ends the session whether or not a basket is open.
        $logoutAt = strpos($html, 'data-counter-logout');
        $this->assertStringContainsString('window.confirm(', substr($html, max(0, $logoutAt - 900), 900));
    }

    /** The permission that gates it is the one the prompt names — staff.manage, which STAFF does not hold. */
    public function test_the_gate_is_staff_manage(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);

        $this->assertFalse($staff->can('staff.manage'));
        $this->assertTrue($owner->can('staff.manage'));
    }
}
