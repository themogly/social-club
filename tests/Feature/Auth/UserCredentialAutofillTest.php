<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 163 — a browser password manager fills any password input on a domain it holds credentials
 * for. On the staff form that value was merely "non-empty", so it dehydrated, the `hashed` cast made a
 * fresh (salted, therefore different) bcrypt hash, and the edited user's password was silently
 * replaced with the ADMIN'S OWN. The visible symptom was the mild one — AuthenticateSession saw the
 * hash move and signed the editing admin out ("saving a PIN signs me out").
 *
 * Every test here fills the credential state WITHOUT the intent toggle, which is exactly what autofill
 * does: a value present in the form state that no operator typed.
 */
class UserCredentialAutofillTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private User $admin;

    private string $adminPassword = 'the-admins-own-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($location->id);

        $this->admin = User::factory()->create(['password' => bcrypt($this->adminPassword)]);
        $this->admin->assignRole(Role::OWNER->value);
        $this->actingAs($this->admin);
    }

    private function staffMember(string $password = 'the-staff-members-password', string $pin = '4321'): User
    {
        $user = User::factory()->create(['password' => bcrypt($password), 'pin' => $pin]);
        $user->assignRole(Role::STAFF->value);

        return $user;
    }

    /** Resolve a field off the real resource schema, hidden ones included. */
    private function field(string $name): Field
    {
        $harness = new class extends LivewireComponent implements HasSchemas
        {
            use InteractsWithSchemas;

            public function render(): string
            {
                return '<div></div>';
            }
        };

        foreach (UserResource::form(Schema::make($harness))->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field && $component->getName() === $name) {
                return $component;
            }
        }

        $this->fail("The user form has no '{$name}' field.");
    }

    // --- the browser-level defence -------------------------------------------------------------

    public function test_both_credential_inputs_declare_autocomplete_new_password(): void
    {
        // "off" is ignored by Chrome on a password field; "new-password" is the hint it honours.
        $this->assertSame('new-password', $this->field('password')->getAutocomplete());
        $this->assertSame('new-password', $this->field('pin')->getAutocomplete());
    }

    public function test_the_attribute_reaches_the_rendered_create_form(): void
    {
        Livewire::test(CreateUser::class)->assertSee('new-password', escape: false);
    }

    // --- the value-level defence ---------------------------------------------------------------

    public function test_a_populated_but_untouched_password_field_does_not_change_the_stored_hash(): void
    {
        $staff = $this->staffMember();
        $before = $staff->getRawOriginal('password');

        // Autofill: the admin's own password lands in the form state; nobody asked to set one.
        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['password' => $this->adminPassword])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $staff->fresh()?->getRawOriginal('password'));
    }

    public function test_a_populated_but_untouched_pin_field_does_not_change_the_stored_pin(): void
    {
        $staff = $this->staffMember();
        $before = $staff->getRawOriginal('pin');

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['pin' => '9999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $staff->fresh()?->getRawOriginal('pin'));
    }

    public function test_setting_only_a_pin_leaves_the_password_hash_byte_identical(): void
    {
        // The reported case: the admin edits their OWN row to set a PIN and the manager fills the
        // password box too. The hash must not move — that is the whole reason the session survives,
        // because AuthenticateSession invalidates on exactly this comparison.
        $before = $this->admin->getRawOriginal('password');

        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->fillForm(['set_pin' => true, 'pin' => '5678', 'password' => $this->adminPassword])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $this->admin->fresh();
        $this->assertSame($before, $fresh?->getRawOriginal('password'));
        $this->assertTrue(Hash::check('5678', (string) $fresh?->getRawOriginal('pin')));
    }

    // --- the security case ---------------------------------------------------------------------

    public function test_editing_another_users_record_never_rewrites_their_password(): void
    {
        $staff = $this->staffMember();
        $staffHash = $staff->getRawOriginal('password');
        $adminHash = $this->admin->getRawOriginal('password');

        // An owner opens a staff row to change the name; Chrome fills its saved credentials for the
        // domain — the OWNER'S. Before the fix this replaced the staff member's password with the
        // owner's, and only the owner could then sign in as them.
        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['name' => 'Nombre corregido', 'password' => $this->adminPassword])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $staff->fresh();
        $this->assertSame('Nombre corregido', $fresh?->name);
        $this->assertSame($staffHash, $fresh?->getRawOriginal('password'));
        $this->assertTrue(Hash::check('the-staff-members-password', (string) $fresh?->getRawOriginal('password')));
        $this->assertFalse(Hash::check($this->adminPassword, (string) $fresh?->getRawOriginal('password')));
        $this->assertSame($adminHash, $this->admin->fresh()?->getRawOriginal('password'));
    }

    // --- deliberate changes still work ----------------------------------------------------------

    public function test_deliberately_setting_a_new_password_still_works(): void
    {
        $staff = $this->staffMember();
        $before = $staff->getRawOriginal('password');

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['set_password' => true, 'password' => 'a-deliberately-chosen-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $staff->fresh();
        $this->assertNotSame($before, $fresh?->getRawOriginal('password'));
        $this->assertTrue(Hash::check('a-deliberately-chosen-password', (string) $fresh?->getRawOriginal('password')));
    }

    public function test_asking_to_set_a_password_and_leaving_it_blank_is_refused(): void
    {
        $staff = $this->staffMember();

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['set_password' => true, 'password' => ''])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_a_new_user_is_still_created_with_the_password_that_was_typed(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nueva persona',
                'email' => 'nueva@example.test',
                'password' => 'a-brand-new-password',
                'roles' => [\Spatie\Permission\Models\Role::query()->where('name', Role::STAFF->value)->sole()->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'nueva@example.test')->sole();
        $this->assertTrue(Hash::check('a-brand-new-password', (string) $created->getRawOriginal('password')));
    }

    // --- the middleware this branch must NOT weaken ----------------------------------------------

    public function test_authenticate_session_is_still_registered_on_the_panel(): void
    {
        // Invalidating every session when a password changes is precisely what it is for; making the
        // symptom go away by removing it would have been the tempting wrong fix.
        $this->assertContains(AuthenticateSession::class, Filament::getPanel('admin')->getMiddleware());
    }

    // --- the trail ------------------------------------------------------------------------------

    public function test_a_deliberate_credential_change_is_audited_without_credential_material(): void
    {
        $staff = $this->staffMember();

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['set_password' => true, 'password' => 'a-deliberately-chosen-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'user.password.updated')->sole();
        $this->assertSame($this->admin->getKey(), $log->actor_id);
        $this->assertSame($staff->getKey(), $log->auditable_id);
        $this->assertNull($log->before);
        $this->assertNull($log->after);
    }

    public function test_an_untouched_credential_writes_no_audit_row(): void
    {
        $staff = $this->staffMember();

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['name' => 'Nombre corregido', 'password' => $this->adminPassword])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->whereIn('action', ['user.password.updated', 'user.pin.updated'])->count());
    }
}
