<?php

namespace Tests\Feature\Prelaunch;

use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LimitSnapshot;
use App\Support\Money;
use App\Support\NextSteps;
use App\Support\Weight;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 306 — the rough edges of the pre-launch browser pass: a weight with an English decimal point in a panel table,
 * English text inside the *Recuento* dialog, decimal commas that desktop Chrome refused, a zero daily allowance in green,
 * and csc:install's outdated closing advice. (The one-line last-sale strip is proven in a browser: prove-306-small-fixes.)
 */
class SmallFixesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->centro->id]);
        $this->actingAs($owner);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
    }

    // --- 1. Weights in panel tables ---------------------------------------------------------------------------------------

    public function test_the_batch_list_shows_weights_through_the_one_formatter(): void
    {
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->centro->id, 'remaining_cg' => 30001]);

        Livewire::test(ListBatches::class)->assertSee(Weight::fromCentigrams(30001)->formatted())->assertSee('300.01 g')->assertDontSee('300,01 g'); // 316: a point everywhere
    }

    // --- 2. No generated English label in the Recuento dialog ---------------------------------------------------------------

    public function test_the_recount_dialog_has_no_generated_english_label(): void
    {
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->centro->id, 'remaining_cg' => 49300]);

        Livewire::test(ListBatches::class)->mountTableAction('recount', $batch)
            ->assertMountedActionModalDontSeeHtml('In system')
            ->assertMountedActionModalSee(__('En sistema'));
    }

    // --- 3. Decimal commas in panel number fields --------------------------------------------------------------------------

    public function test_a_gram_amount_saves_the_same_typed_three_ways_and_nonsense_is_refused(): void
    {
        foreach (['1000,01', '1000.01', '1.000,01'] as $i => $typed) {
            Livewire::test(CreateBatch::class)
                ->fillForm(['genetic_id' => $this->genetic->id, 'label' => "Lote {$i}", 'sale_price_eur' => '10,50', 'grams' => $typed])
                ->set('data.location_id', [$this->centro->id])
                ->call('create')
                ->assertHasNoFormErrors();
            $batch = Batch::query()->withoutGlobalScopes()->where('label', "Lote {$i}")->sole();
            $this->assertSame(100001, $batch->remaining_cg->centigrams, "«{$typed}» did not save as 1000,01 g");
            $this->assertSame(1050, $batch->price_per_gram_cents);
        }

        Livewire::test(CreateBatch::class)
            ->fillForm(['genetic_id' => $this->genetic->id, 'sale_price_eur' => '10', 'grams' => 'abc'])
            ->set('data.location_id', [$this->centro->id])
            ->call('create')
            ->assertHasFormErrors(['grams']);
    }

    public function test_the_panel_decimal_field_is_a_text_input_that_asks_for_a_decimal_keyboard(): void
    {
        $html = Livewire::test(CreateBatch::class)->fillForm(['genetic_id' => $this->genetic->id])->set('data.location_id', [$this->centro->id])->html();

        $this->assertSame(1, preg_match('/<input[^>]*id="form\.grams"[^>]*>/', $html, $input));
        $this->assertStringContainsString('type="text"', $input[0]);
        $this->assertStringContainsString('inputmode="decimal"', $input[0]);
    }

    public function test_the_one_parser_reads_the_full_spanish_and_english_forms_and_still_refuses_the_ambiguous_ones(): void
    {
        $this->assertSame('1000.01', Weight::canonicalGrams('1.000,01'));
        $this->assertSame('1000.01', Weight::canonicalGrams('1,000.01'));
        $this->assertNull(Weight::canonicalGrams('1.000'));
        $this->assertNull(Weight::canonicalGrams('1,000'));
        $this->assertSame(125000, Money::parseTyped('1.250,00'));
        $this->assertNull(Money::parseTyped('1.250'));
    }

    // --- 5. A zero daily allowance is not green -------------------------------------------------------------------------------

    public function test_the_daily_allowance_is_green_amber_then_red(): void
    {
        $this->assertSame('ok', (new LimitSnapshot(1000, 60000, 500, 500))->dailyRemainingState());
        $this->assertSame('low', (new LimitSnapshot(1000, 60000, 800, 800))->dailyRemainingState());
        $this->assertSame('empty', (new LimitSnapshot(1000, 60000, 1000, 1000))->dailyRemainingState());
        $this->assertSame('empty', (new LimitSnapshot(1000, 60000, 0, 0))->dailyRemainingState(0));

        foreach (['livewire/counter/partials/member-cart-summary', 'livewire/counter/membership-counter'] as $view) {
            $source = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('dailyRemainingState()', $source, "{$view}: «Restante hoy» follows the DAILY allowance");
        }
    }

    // --- 6. csc:install's closing advice ---------------------------------------------------------------------------------------

    public function test_install_and_reset_give_the_same_up_to_date_next_steps(): void
    {
        $install = (string) file_get_contents(app_path('Console/Commands/Install.php'));
        $reset = (string) file_get_contents(app_path('Console/Commands/ResetForLaunch.php'));

        $this->assertStringNotContainsString('price every genetic', $install);
        $this->assertStringContainsString('NextSteps::setUp()', $install);
        $this->assertStringContainsString('NextSteps::setUp()', $reset);
        $this->assertStringContainsString(__('Crear lote'), implode(' ', NextSteps::setUp()));
    }
}
