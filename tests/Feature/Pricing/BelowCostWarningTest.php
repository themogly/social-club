<?php

namespace Tests\Feature\Pricing;

use App\Actions\Stock\IntakeBatch;
use App\Enums\Role;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BelowCost;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Wizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 295 (Shane's note 5) — a sale price below cost asks *"are you sure?"* before anything is written.
 *
 * *Volver y corregir* saves nothing; *Continuar* saves as entered, and the price's (or intake's) existing audit entry
 * records `below_cost: true`. Equal to cost, no cost and a zero cost never ask. A warning, never a block.
 */
class BelowCostWarningTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
    }

    /** @param  array<string, mixed>  $overrides */
    private function strain(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amnesia Haze', 'product_type' => 'FLOWER', 'grams' => 100,
            'cost_per_gram_eur' => '9.50', 'location_id' => $this->sede->id, 'price_per_gram_eur' => '8',
        ], $overrides);
    }

    private function intakeAudit(): ?AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.intake')->latest('id')->first();
    }

    // --- The rule -----------------------------------------------------------------------------------------------------

    public function test_the_rule_per_gram_per_unit_and_per_eighth(): void
    {
        $this->assertSame(['per_gram'], array_column(BelowCost::offences(950, perGramCents: 800), 'field'));
        $this->assertSame([], BelowCost::offences(800, perGramCents: 800), 'equal to cost is not below it');
        $this->assertSame([], BelowCost::offences(null, perGramCents: 100), 'no cost, nothing to compare');
        $this->assertSame([], BelowCost::offences(0, perGramCents: 100), 'a zero cost, nothing to compare');

        // €3,00/g × 1,5 g a unit = €4,50 a unit: €4,49 is below, €4,50 is not.
        $this->assertSame(['per_unit'], array_column(BelowCost::offences(300, perUnitCents: 449, gramsPerUnitCg: 150), 'field'));
        $this->assertSame([], BelowCost::offences(300, perUnitCents: 450, gramsPerUnitCg: 150));

        // €9,00/g × 3,5 g = €31,50 an eighth, however cheap or dear the gram.
        $this->assertSame(['per_eighth'], array_column(BelowCost::offences(900, perGramCents: 1000, perEighthCents: 3149), 'field'));
        $this->assertSame([], BelowCost::offences(900, perGramCents: 1000, perEighthCents: 3150));
    }

    // --- Añadir variedad ----------------------------------------------------------------------------------------------

    public function test_the_wizard_asks_before_creating_and_go_back_saves_nothing(): void
    {
        $component = Livewire::test(CreateGenetic::class)
            ->fillForm($this->strain())
            ->call('create')
            ->assertActionMounted('belowCost');
        $component->assertMountedActionModalSee([__('El precio de venta es menor que el coste'), __('Precio por gramo'), __('Coste por gramo')]);
        $component->call('unmountAction'); // Volver y corregir

        $this->assertSame(0, Genetic::query()->withoutGlobalScopes()->count(), 'Volver y corregir saved the strain');
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
    }

    public function test_continue_creates_it_as_entered_and_the_intake_says_below_cost(): void
    {
        Livewire::test(CreateGenetic::class)
            ->fillForm($this->strain())
            ->call('create')
            ->assertActionMounted('belowCost')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $batch = Batch::query()->withoutGlobalScopes()->sole();
        $this->assertSame(800, $batch->price_per_gram_cents);
        $this->assertSame(950, $batch->cost_per_gram_cents);
        $this->assertTrue((bool) ($this->intakeAudit()?->after['below_cost'] ?? false));
    }

    public function test_the_wizard_also_asks_when_leaving_the_price_step(): void
    {
        // What the browser does on "Siguiente" from the Precio step (index 4): the wizard validates it and moves on.
        $component = Livewire::test(CreateGenetic::class)->fillForm($this->strain());
        $wizard = $component->instance()->getSchema('form')->getComponent(fn ($c): bool => $c instanceof Wizard, withHidden: true);
        $component->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', [4])
            ->assertActionMounted('belowCost')
            ->assertNotDispatched('next-wizard-step');

        // Continuar moves on to the photo step; nothing is created until Crear.
        $component->callMountedAction()->assertDispatched('next-wizard-step');
        $this->assertSame(0, Genetic::query()->withoutGlobalScopes()->count());
    }

    public function test_a_unit_strain_compares_the_unit_price_with_the_cost_of_a_unit(): void
    {
        Livewire::test(CreateGenetic::class)
            ->fillForm([
                'name' => 'Preroll', 'product_type' => 'PREROLL', 'grams_per_unit_g' => '1', 'units' => 10,
                'cost_per_gram_eur' => '5', 'location_id' => $this->sede->id, 'price_per_unit_eur' => '4.99',
            ])
            ->call('create')
            ->assertActionMounted('belowCost');
    }

    public function test_equal_to_cost_and_no_cost_do_not_ask(): void
    {
        Livewire::test(CreateGenetic::class)->fillForm($this->strain(['price_per_gram_eur' => '9.50']))->call('create')
            ->assertActionNotMounted('belowCost')->assertHasNoFormErrors();
        Livewire::test(CreateGenetic::class)->fillForm($this->strain(['name' => 'Sin coste', 'cost_per_gram_eur' => null]))->call('create')
            ->assertActionNotMounted('belowCost')->assertHasNoFormErrors();
        Livewire::test(CreateGenetic::class)->fillForm($this->strain(['name' => 'Coste cero', 'cost_per_gram_eur' => '0']))->call('create')
            ->assertActionNotMounted('belowCost')->assertHasNoFormErrors();

        $this->assertSame(3, Genetic::query()->withoutGlobalScopes()->count());
        $this->assertArrayNotHasKey('below_cost', (array) $this->intakeAudit()?->after);
    }

    // --- Añadir stock -------------------------------------------------------------------------------------------------

    public function test_adding_stock_asks_for_the_gram_and_the_eighth(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        $component = Livewire::test(CreateBatch::class)
            ->fillForm([
                'location_id' => $this->sede->id, 'genetic_id' => $genetic->id, 'grams' => '50',
                'cost_per_gram_eur' => '9.50', 'sale_price_eur' => '8', 'price_per_eighth_eur' => '30',
            ])
            ->call('create')
            ->assertActionMounted('belowCost');

        // Both prices, each with its own cost: 3,5 g at €9,50 is €33,25.
        $component->assertMountedActionModalSee([__('Precio por gramo'), __('Coste de 3,5 g'), Money::fromCents(3325)->formatted()]);
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());

        $component->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame(1, Batch::query()->withoutGlobalScopes()->count());
        $this->assertTrue((bool) ($this->intakeAudit()?->after['below_cost'] ?? false));
    }

    // --- Precio -------------------------------------------------------------------------------------------------------

    public function test_the_price_action_asks_and_continue_records_below_cost(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = (new IntakeBatch)->handle($genetic, $this->sede, ['grams' => '100', 'cost_per_gram_cents' => 950, 'price_per_gram_cents' => 1200]);

        $list = Livewire::test(ListBatches::class)
            ->callTableAction('price', $batch, ['rate_eur' => '8'])
            ->assertActionMounted('belowCost');
        $this->assertSame(1200, $batch->fresh()->price_per_gram_cents, 'the price changed before Continuar');

        $list->callMountedAction();

        $this->assertSame(800, $batch->fresh()->price_per_gram_cents);
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.price.updated')->sole();
        $this->assertTrue((bool) ($audit->after['below_cost'] ?? false));
    }

    public function test_the_price_action_does_not_ask_at_or_above_cost(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = (new IntakeBatch)->handle($genetic, $this->sede, ['grams' => '100', 'cost_per_gram_cents' => 950, 'price_per_gram_cents' => 1200]);

        Livewire::test(ListBatches::class)
            ->callTableAction('price', $batch, ['rate_eur' => '9.50'])
            ->assertActionNotMounted('belowCost');

        $this->assertSame(950, $batch->fresh()->price_per_gram_cents);
        $this->assertArrayNotHasKey('below_cost', (array) AuditLog::query()->withoutGlobalScopes()->where('action', 'batch.price.updated')->sole()->after);
    }
}
