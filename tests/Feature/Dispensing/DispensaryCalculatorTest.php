<?php

namespace Tests\Feature\Dispensing;

use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 292 — the dispensary keypad answers instantly; the € calculator is a per-sede option, OFF by default (the
 * owner's decision), and correct when on.
 *
 * Two defects: every key was a full server round trip (the pad looked disabled while the whole component re-rendered),
 * and the preview read typed EUROS as GRAMS in calculator mode (€20 at €10/g showed 20,00 g and "nothing left today").
 * The basket was never wrong — addLine() used the right resolver; the preview used a second, wrong one.
 */
class DispensaryCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private Genetic $flower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);

        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Stinky feet']);
        foreach ([$this->centro, $this->norte] as $sede) {
            GeneticPrice::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id, 'location_id' => $sede->id, 'price_per_gram_cents' => 1000,
            ]);
            Batch::factory()->create([
                'organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id, 'location_id' => $sede->id,
                'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000,
            ]);
        }
    }

    private function serve(Location $at): Testable
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$at->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($at->id);
        CounterOperator::set($user);

        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay()]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $at->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->call('chooseGenetic', $this->flower->id);
    }

    private function enableCalculator(Location $at): void
    {
        Settings::set('dispensary_calculator_enabled', true, SettingType::BOOL, $at->id);
    }

    // 1 -------------------------------------------------------------------------------------------------------------

    public function test_the_calculator_is_off_everywhere_by_default_and_not_offered(): void
    {
        $this->assertFalse((bool) Settings::get('dispensary_calculator_enabled', null, $this->centro->id));
        $this->assertFalse((bool) Settings::get('dispensary_calculator_enabled', null, $this->norte->id));

        $this->serve($this->centro)->assertDontSee('data-calculator-toggle', false);
    }

    // 2 -------------------------------------------------------------------------------------------------------------

    public function test_with_the_calculator_off_the_server_treats_every_value_as_grams(): void
    {
        $pos = $this->serve($this->centro);

        $pos->call('addLine', '20', 'calculator')->assertSet('calculatorMode', false); // the real path: addLine carries the mode

        $this->assertSame(2000, (int) collect($pos->get('basket'))->sum('grams_cg'), '20 typed = 20,00 g, never a €20 back-solve');
    }

    // 3 -------------------------------------------------------------------------------------------------------------

    public function test_turning_it_on_at_one_sede_offers_it_there_only(): void
    {
        $this->enableCalculator($this->centro);

        $this->serve($this->centro)->assertSee('data-calculator-toggle', false);
        $this->serve($this->norte)->assertDontSee('data-calculator-toggle', false);
    }

    // 4 -------------------------------------------------------------------------------------------------------------

    public function test_the_sede_form_carries_the_switch_in_both_languages(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($owner)->test(EditLocation::class, ['record' => $this->centro->getRouteKey()])
            ->assertFormFieldExists('dispensary_calculator_enabled')
            ->assertSee(__('Calculadora € en el dispensario'));

        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $this->assertSame('€ calculator in the dispensary', $en['Calculadora € en el dispensario'] ?? null);
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_with_the_calculator_on_twenty_euros_at_ten_a_gram_previews_two_grams(): void
    {
        $this->enableCalculator($this->centro);
        $pos = $this->serve($this->centro)->set('calculatorMode', true)->set('weightInput', '20'); // the keypad's € switch is client-side (292)

        $this->assertSame(200, $pos->instance()->activeEntryGramsCg());
        $pos->assertSee('data-entry-grams="200"', false);
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    public function test_remaining_after_this_entry_subtracts_the_back_solved_grams(): void
    {
        $this->enableCalculator($this->centro);
        $pos = $this->serve($this->centro)->set('calculatorMode', true)->set('weightInput', '20'); // the keypad's € switch is client-side (292)

        // Default daily limit 3,5 g → 3,5 − 2,0 = 1,5 g left, not "0,00 g" (which 20 g would have claimed).
        $pos->assertSee('data-remaining-after="150"', false);
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    public function test_add_to_basket_adds_what_the_server_computes_in_both_modes(): void
    {
        $this->enableCalculator($this->centro);
        $pos = $this->serve($this->centro);

        $pos->call('addLine', '1,5', 'grams');
        $pos->call('chooseGenetic', $this->flower->id)->call('addLine', '20', 'calculator'); // adding closes the panel, as ever

        $this->assertSame([150, 200], collect($pos->get('basket'))->pluck('grams_cg')->map(fn ($v): int => (int) $v)->all());
    }

    // 11 ------------------------------------------------------------------------------------------------------------

    public function test_a_tampered_value_is_refused_and_presets_still_follow_the_limit(): void
    {
        $pos = $this->serve($this->centro);

        foreach (['-5', 'abc', '1,234', ''] as $bad) {
            $pos->call('addLine', $bad, 'grams');
        }
        $this->assertSame([], $pos->get('basket'));

        $presets = collect($pos->instance()->quickEntryPresets())->keyBy('grams_cg');
        $this->assertTrue($presets[100]['available']);
        $this->assertFalse($presets[500]['available'], 'a 5 g preset is over the 3,5 g daily limit');
    }

    public function test_the_pad_no_longer_posts_each_key(): void
    {
        $html = (string) file_get_contents(resource_path('views/livewire/counter/dispensary-pos.blade.php'));

        $this->assertStringNotContainsString('wire:click="pad(', $html);
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('window.dispensaryPad', $js);
        $this->assertStringContainsString('$wire.addLine(this.value', $js); // the value goes to the server only with "Añadir"
        $this->assertStringContainsString('window.dispensaryPad(', $html);
    }
}
