<?php

namespace Tests\Feature\Users;

use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\PinLookup;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 322 — on live, a PIN saved from the panel was not the PIN that was typed. The code saves what it is sent; the
 * input was a masked PASSWORD field with `autocomplete="new-password"`, which is exactly what a Mac's password manager
 * fills with a saved password or a suggested one. The PIN is now an ordinary text input (visually masked, numeric,
 * ignored by password managers), typed twice, and the save says where it will work; *Probar PIN* checks it.
 */
class PinFieldTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id]);
        $this->actingAs($this->owner);
    }

    private function staff(string $name = 'Nick', ?string $pin = '1111', array $locations = []): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync($locations === [] ? [$this->centro->id] : $locations);

        return $user;
    }

    private static function found(string $pin): ?User
    {
        return User::query()->where('pin_lookup', PinLookup::for($pin))->first();
    }

    private function newUser(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nick', 'email' => 'nick@club.test', 'password' => 'una-contraseña-larga',
            'roles' => [\Spatie\Permission\Models\Role::findByName(Role::STAFF->value)->id], 'locations' => [$this->centro->id],
            'pin' => '4321', 'pin_confirmation' => '4321',
        ], $overrides);
    }

    // --- 1–2. The field ----------------------------------------------------------------------------------------------------

    public function test_the_pin_input_is_not_a_password_field_and_carries_the_ignore_hints(): void
    {
        $html = Livewire::test(CreateUser::class)->html();
        $this->assertSame(1, preg_match('/<input[^>]*id="form\.pin"[^>]*>/', $html, $m), 'no PIN input rendered');
        $input = $m[0];

        $this->assertStringNotContainsString('type="password"', $input);
        foreach (['inputmode="numeric"', 'autocomplete="off"', 'data-1p-ignore', 'data-lpignore="true"', 'data-bwignore', 'data-form-type="other"', 'pattern="[0-9]*"'] as $attribute) {
            $this->assertStringContainsString($attribute, $input, "the PIN input lacks {$attribute}");
        }
    }

    public function test_a_pin_with_anything_but_digits_is_refused_on_the_server(): void
    {
        foreach (['12a4', '12.4', '123', '123456789'] as $pin) {
            Livewire::test(CreateUser::class)->fillForm($this->newUser(['email' => "x{$pin}@club.test", 'pin' => $pin, 'pin_confirmation' => $pin]))
                ->call('create')->assertHasFormErrors(['pin']);
        }
        $this->assertSame(0, User::query()->where('name', 'Nick')->count());
    }

    // --- 3. Typed twice ----------------------------------------------------------------------------------------------------

    public function test_create_refuses_a_repeat_that_does_not_match_and_saves_the_typed_pin_when_it_does(): void
    {
        Livewire::test(CreateUser::class)->fillForm($this->newUser(['pin_confirmation' => '4322']))->call('create')
            ->assertHasFormErrors(['pin_confirmation' => __('Los PIN no coinciden.')]);
        $this->assertNull(User::query()->where('email', 'nick@club.test')->first());

        Livewire::test(CreateUser::class)->fillForm($this->newUser())->call('create')->assertHasNoFormErrors();
        $this->assertSame('nick@club.test', self::found('4321')?->email);
    }

    public function test_edit_refuses_a_repeat_that_does_not_match_and_saves_the_typed_pin_when_it_does(): void
    {
        $nick = $this->staff();

        Livewire::test(EditUser::class, ['record' => $nick->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '4321', 'pin_confirmation' => '1234'])->call('save')
            ->assertHasFormErrors(['pin_confirmation' => __('Los PIN no coinciden.')]);
        $this->assertSame($nick->id, self::found('1111')?->id, 'a refused save changed the PIN');

        Livewire::test(EditUser::class, ['record' => $nick->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '4321', 'pin_confirmation' => '4321'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($nick->id, self::found('4321')?->id);
    }

    // --- 4. The confirmation --------------------------------------------------------------------------------------------------

    public function test_the_save_names_the_sedes_where_the_pin_works_or_warns_there_are_none(): void
    {
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        $closed = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Cerrada', 'active' => false]);
        $nick = $this->staff(locations: [$this->centro->id, $store->id, $closed->id]);

        Livewire::test(EditUser::class, ['record' => $nick->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '4321', 'pin_confirmation' => '4321'])->call('save')
            ->assertNotified(__('PIN guardado para :name. Pruébalo en el mostrador de :sedes.', ['name' => 'Nick', 'sedes' => 'Dream Green']));

        $lone = $this->staff('Lone', '2222');
        $lone->locations()->sync([]);
        Livewire::test(EditUser::class, ['record' => $lone->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '5678', 'pin_confirmation' => '5678', 'locations' => []])->call('save')
            ->assertNotified(__('El PIN no funcionará: esta persona no tiene ninguna sede asignada.'));
    }

    // --- 5. Probar PIN ----------------------------------------------------------------------------------------------------------

    public function test_probar_pin_answers_only_for_this_person_is_throttled_and_audited_without_the_pin(): void
    {
        $nick = $this->staff('Nick', '4321');
        $this->staff('Marta', '8765');
        $page = fn () => Livewire::test(EditUser::class, ['record' => $nick->getRouteKey()]);

        $page()->callAction('testPin', ['pin' => '4321'])->assertNotified(__('Coincide'));
        $page()->callAction('testPin', ['pin' => '8765'])->assertNotified(__('No coincide')); // Marta's: never named
        $this->assertStringNotContainsString('Marta', (string) json_encode(session('filament.notifications')));
        $page()->callAction('testPin', ['pin' => '0000'])->assertNotified(__('No coincide'));

        $audits = AuditLog::query()->where('action', 'user.pin.tested')->get();
        $this->assertCount(3, $audits);
        $this->assertStringNotContainsString('4321', (string) json_encode($audits->toArray()));
        $this->assertStringNotContainsString('8765', (string) json_encode($audits->toArray()));

        $page()->callAction('testPin', ['pin' => '0001']);
        $page()->callAction('testPin', ['pin' => '0002']);
        $page()->callAction('testPin', ['pin' => '4321'])->assertNotified(__('Demasiados intentos. Espera una hora antes de volver a probar un PIN.'));
    }

    public function test_probar_pin_needs_the_permission_that_edits_the_person(): void
    {
        $nick = $this->staff();
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->centro->id]);
        $other = $this->staff('Marta', '8765');
        $this->actingAs($this->owner);
        Livewire::test(EditUser::class, ['record' => $nick->getRouteKey()])->assertActionVisible('testPin');

        $this->actingAs($manager);
        if (! $manager->can('update', $other)) {
            $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertForbidden();
        } else {
            Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])->assertActionVisible('testPin');
        }
    }

    // --- 6. The storage rule of 286 (a pin) ------------------------------------------------------------------------------------

    public function test_setting_a_pin_clears_a_legacy_hash_and_writes_only_the_lookup(): void
    {
        $nick = $this->staff(pin: Hash::make('1111'));
        $this->assertNotNull($nick->getRawOriginal('pin'));

        Livewire::test(EditUser::class, ['record' => $nick->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '4321', 'pin_confirmation' => '4321'])->call('save')->assertHasNoFormErrors();

        $fresh = $nick->fresh();
        $this->assertNull($fresh->getRawOriginal('pin'));
        $this->assertSame(PinLookup::for('4321'), $fresh->getRawOriginal('pin_lookup'));
    }
}
