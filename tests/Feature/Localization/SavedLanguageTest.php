<?php

namespace Tests\Feature\Localization;

use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\LocaleSwitcher;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Alerts\AlertRecipients;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/**
 * Prompt 315 — every person has a language SAVED. `users.locale` used to be written only by the top-bar switcher, so an
 * account that never tapped ES/EN silently followed a fallback: its emails, alerts and counter depended on settings the
 * person never saw. New accounts get the club's default (one `creating` hook), existing empty ones are backfilled, and
 * *Idioma* is on the profile and the user form. A saved choice always wins; changing the club default moves nobody.
 */
class SavedLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('default_locale', 'en', SettingType::STRING); // the club's default is English here
        $this->owner = User::factory()->create(['locale' => 'es']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    // --- 1. New accounts ------------------------------------------------------------------------------------------------

    public function test_every_way_of_creating_a_person_saves_the_club_default_and_an_explicit_choice_is_kept(): void
    {
        $this->assertSame('en', User::create(['name' => 'Directo', 'email' => 'directo@example.test', 'password' => 'secreto-123'])->locale);
        $this->assertSame('es', User::factory()->create(['locale' => 'es'])->locale);

        Livewire::test(CreateUser::class)
            ->assertSchemaStateSet(['locale' => 'en'])
            ->fillForm(['name' => 'Nueva Persona', 'email' => 'nueva@example.test', 'set_password' => true, 'password' => 'secreto-123456',
                'roles' => [SpatieRole::findByName(Role::STAFF->value)->id], 'locations' => [$this->sede->id]])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('en', User::query()->where('email', 'nueva@example.test')->sole()->locale);
    }

    public function test_install_saves_the_club_default_on_the_first_owner(): void
    {
        DB::table('users')->delete();
        $this->artisan('csc:install', [
            '--name' => 'Club Ejemplo', '--legal-name' => 'Asociación Ejemplo', '--tax-id' => 'G12345678',
            '--contact-email' => 'info@example.es', '--owner-name' => 'Ana', '--owner-email' => 'ana@example.es',
            '--owner-password' => 'sup3rsecret', '--force' => true,
        ])->assertSuccessful();

        $this->assertNotNull(User::query()->where('email', 'ana@example.es')->sole()->locale);
    }

    // --- 2. The backfill ------------------------------------------------------------------------------------------------------

    public function test_the_backfill_fills_empty_languages_and_leaves_saved_ones(): void
    {
        $empty = User::factory()->create();
        DB::table('users')->where('id', $empty->id)->update(['locale' => null]); // as live has them, before the hook
        $chosen = User::factory()->create(['locale' => 'es']);

        (require database_path('migrations/2026_09_29_400000_backfill_user_locales.php'))->up();

        $this->assertSame('en', $empty->fresh()->locale);
        $this->assertSame('es', $chosen->fresh()->locale);
        $this->assertSame(0, User::query()->whereNull('locale')->count());
    }

    // --- 3–4. The fields --------------------------------------------------------------------------------------------------------

    public function test_the_profile_language_saves_and_the_panel_follows(): void
    {
        Livewire::test(EditProfile::class)
            ->assertSchemaStateSet(['locale' => 'es'])
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('en', $this->owner->fresh()->locale);
        $this->assertSame('en', session('locale'), 'the panel switches at once, as the top-bar switcher does');
        $this->get(Filament::getUrl())->assertOk()->assertSee('lang="en"', false);
    }

    public function test_the_user_form_sets_someone_elses_language_and_audits_it(): void
    {
        $staff = User::factory()->create(['locale' => 'en']);
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$this->sede->id]);

        Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
            ->assertSchemaStateSet(['locale' => 'en'])
            ->fillForm(['locale' => 'es'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('es', $staff->fresh()->locale);
        $entry = AuditLog::query()->where('action', 'user.locale.changed')->sole();
        $this->assertSame(['locale' => 'en'], $entry->before);
        $this->assertSame(['locale' => 'es'], $entry->after);
    }

    // --- 5–6. A saved choice wins; one value -----------------------------------------------------------------------------------

    public function test_changing_the_club_default_moves_nobody_who_has_a_language(): void
    {
        $staff = User::factory()->create(['locale' => 'en']);

        Settings::set('default_locale', 'es', SettingType::STRING);

        $this->assertSame('en', $staff->fresh()->locale);
        $this->assertSame('es', User::factory()->create()->locale, 'a new person takes the new default');
    }

    public function test_the_top_bar_switcher_and_the_profile_are_the_same_value(): void
    {
        Livewire::test(LocaleSwitcher::class)->call('switchLocale', 'en');
        Livewire::test(EditProfile::class)->assertSchemaStateSet(['locale' => 'en']);

        Livewire::test(EditProfile::class)->fillForm(['locale' => 'es'])->call('save');
        $this->assertSame('es', $this->owner->fresh()->locale);
        $this->assertSame('es', session('locale'));
    }

    // --- 7. Telegram per person (a pin) -----------------------------------------------------------------------------------------

    public function test_an_alert_is_written_in_the_persons_saved_language(): void
    {
        $person = User::factory()->create(['locale' => 'es']);

        $this->assertSame('es', AlertRecipients::inTheirLanguage($person, fn (): string => app()->getLocale()));
    }
}
