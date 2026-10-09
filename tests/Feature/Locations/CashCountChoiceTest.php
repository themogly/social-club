<?php

namespace Tests\Feature\Locations;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 379 — Ben, on Dream Green's *Cajas*: "Toggle is confusing." A switch «Contar cada noche» in a far column, one per box,
 * none saying what off means. Now, under a row on «Bote propio»: «¿Cuándo se cuenta?» «Cada noche» · «Solo al vaciarlo», with a
 * line that says what each means. The same `count_*_nightly` setting underneath; the close is unchanged.
 */
class CashCountChoiceTest extends TestCase
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
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    private function set(string $key, mixed $value, SettingType $type = SettingType::STRING): void
    {
        Settings::set($key, $value, $type, (string) $this->sede->id);
    }

    // --- 1. Render ---------------------------------------------------------------------------------------------------------------

    public function test_a_box_of_its_own_shows_when_it_is_counted_as_two_buttons_and_no_switch(): void
    {
        $this->set('cash_box_fees', 'own');

        Livewire::test(EditLocation::class, ['record' => $this->sede->id])
            ->assertFormFieldExists('count_fees_nightly', fn ($field): bool => $field instanceof ToggleButtons)
            ->assertFormFieldIsVisible('count_fees_nightly')
            ->assertSee('¿Cuándo se cuenta?')->assertSee('Cada noche')->assertSee('Solo al vaciarlo')
            ->assertSeeHtml('data-cash-count-choice="FEES"')
            ->assertDontSee('Contar cada noche');
    }

    // --- 2. Saving --------------------------------------------------------------------------------------------------------------

    public function test_only_when_emptied_stores_false_and_every_night_true_and_the_page_shows_it_again(): void
    {
        $this->set('cash_box_fees', 'own');

        Livewire::test(EditLocation::class, ['record' => $this->sede->id])->fillForm(['count_fees_nightly' => '0'])->call('save')->assertHasNoFormErrors();
        $this->assertFalse((bool) Settings::get('count_fees_nightly', true, (string) $this->sede->id));
        Livewire::test(EditLocation::class, ['record' => $this->sede->id])->assertFormSet(['count_fees_nightly' => '0'])
            ->assertSee('Al cerrar se puede dejar sin contar; lo que tiene pasa al día siguiente.');

        Livewire::test(EditLocation::class, ['record' => $this->sede->id])->fillForm(['count_fees_nightly' => '1'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue((bool) Settings::get('count_fees_nightly', false, (string) $this->sede->id));
        Livewire::test(EditLocation::class, ['record' => $this->sede->id])->assertFormSet(['count_fees_nightly' => '1'])
            ->assertSee('Al cerrar la caja hay que contarlo.');
    }

    // --- 3. Visibility ----------------------------------------------------------------------------------------------------------

    public function test_in_the_till_or_with_the_bar_there_is_nothing_to_count_so_the_choice_hides(): void
    {
        $this->set('cash_box_fees', 'own');
        $this->set('cash_box_shop', 'own');

        Livewire::test(EditLocation::class, ['record' => $this->sede->id])
            ->assertFormFieldIsVisible('count_fees_nightly')
            ->fillForm(['cash_box_fees' => 'till'])->assertFormFieldIsHidden('count_fees_nightly')
            ->assertFormFieldIsVisible('count_shop_nightly')
            ->fillForm(['cash_box_shop' => 'with_bar'])->assertFormFieldIsHidden('count_shop_nightly');
    }

    // --- 4. The close is unchanged ---------------------------------------------------------------------------------------------

    public function test_the_close_starts_on_count_now_for_every_night_and_on_not_today_for_only_when_emptied(): void
    {
        $this->set('cash_box_fees', 'own');
        $this->set('cash_box_bar', 'own');
        $this->set('count_fees_nightly', true, SettingType::BOOL);
        $this->set('count_bar_nightly', false, SettingType::BOOL);
        (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);

        Livewire::test(TillScreen::class)->call('startClose')
            ->assertSet('potCountNow.FEES', true)   // Cada noche: it is counted at the close
            ->assertSet('potCountNow.BAR', false);  // Solo al vaciarlo: it may be left; its balance carries
    }

    // --- 5. Owner-only ---------------------------------------------------------------------------------------------------------

    public function test_a_manager_sees_both_choices_disabled(): void
    {
        $this->set('cash_box_fees', 'own');
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $this->actingAs($manager);

        Livewire::test(EditLocation::class, ['record' => $this->sede->id])
            ->assertFormFieldIsDisabled('cash_box_fees')
            ->assertFormFieldIsDisabled('count_fees_nightly');
    }
}
