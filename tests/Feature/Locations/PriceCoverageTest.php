<?php

namespace Tests\Feature\Locations;

use App\Actions\Pricing\ResolvePrice;
use App\Enums\BatchStatus;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 376 — Ben, on *Sedes*: "What does no prices and has prices mean?" The badge asked only for a per-strain sede price
 * (`GeneticPrice`), but since 278 the price lives on the batch — a sede priced by batch read «Sin precios: no puede dispensar
 * nada» while its counter dispensed normally; and one priced strain of eight read green. Now it counts the strains in stock
 * the counter can actually price, by the counter's own rule.
 */
class PriceCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($owner);
    }

    private function strain(string $name): Genetic
    {
        return Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name, 'active' => true]);
    }

    /** An open batch with stock at the sede, with its own per-gram price or none. */
    private function batch(Genetic $genetic, Location $at, ?int $pricePerGram, int $remainingCg = 5000): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $at->id,
            'remaining_cg' => $remainingCg, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => $pricePerGram, 'expires_on' => now()->addYear()]);
    }

    private function basePrice(Genetic $genetic, Location $at, int $perGram = 900): void
    {
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $at->id,
            'tier_id' => null, 'price_per_gram_cents' => $perGram, 'active' => true]);
    }

    // --- 1. Batch prices only --------------------------------------------------------------------------------------------------

    public function test_a_sede_priced_only_by_its_batches_reads_all_of_them_priced(): void
    {
        $this->batch($this->strain('Amnesia'), $this->norte, 1109);
        $this->batch($this->strain('Critical'), $this->norte, 1112);

        $this->assertSame(['in_stock' => 2, 'priced' => 2], array_intersect_key($this->norte->priceCoverage(), ['in_stock' => 0, 'priced' => 0]));
        Livewire::test(ListLocations::class)
            ->assertTableColumnStateSet('prices_gap', __('Precios: :priced de :total', ['priced' => 2, 'total' => 2]), $this->norte);
    }

    // --- 2. Partial --------------------------------------------------------------------------------------------------------------

    public function test_a_sede_with_one_strain_unpriced_reads_two_of_three_and_names_it(): void
    {
        $this->batch($this->strain('Amnesia'), $this->norte, 1109);              // priced by its batch
        $fallback = $this->strain('Critical');
        $this->batch($fallback, $this->norte, null);
        $this->basePrice($fallback, $this->norte);                               // priced by the sede's fallback
        $this->batch($this->strain('Moby Dick'), $this->norte, null);            // neither

        $coverage = $this->norte->priceCoverage();
        $this->assertSame(3, $coverage['in_stock']);
        $this->assertSame(2, $coverage['priced']);
        $this->assertSame(['Moby Dick'], array_column($coverage['missing'], 'name'));

        Livewire::test(ListLocations::class)
            ->assertTableColumnStateSet('prices_gap', __('Precios: :priced de :total', ['priced' => 2, 'total' => 3]), $this->norte)
            ->assertSee('Moby Dick');
    }

    // --- 3. It agrees with the counter ---------------------------------------------------------------------------------------------

    public function test_every_strain_counted_as_priced_the_counter_prices_and_every_missing_one_it_cannot(): void
    {
        $this->batch($this->strain('Amnesia'), $this->norte, 1109);
        $fallback = $this->strain('Critical');
        $this->batch($fallback, $this->norte, null);
        $this->basePrice($fallback, $this->norte);
        $this->batch($this->strain('Moby Dick'), $this->norte, null);
        $zero = $this->strain('Gratis');
        $this->batch($zero, $this->norte, 0);                                     // a price of 0 prices nothing

        $coverage = $this->norte->priceCoverage();
        $missing = array_column($coverage['missing'], 'genetic_id');
        foreach (Genetic::query()->whereIn('name', ['Amnesia', 'Critical', 'Moby Dick', 'Gratis'])->get() as $genetic) {
            if (in_array($genetic->id, $missing, true)) {
                try {
                    $rate = (new ResolvePrice)->forGenetic($genetic, $this->norte)->ratePerGramCents;
                    $this->assertSame(0, $rate, "{$genetic->name} is counted missing, so the counter cannot price it");
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1); // no batch price and no fallback: the counter refuses it
                }
            } else {
                $this->assertGreaterThan(0, (new ResolvePrice)->forGenetic($genetic, $this->norte)->ratePerGramCents, "{$genetic->name} is counted priced");
            }
        }
        $this->assertSame(['Gratis', 'Moby Dick'], array_column($coverage['missing'], 'name'));
    }

    // --- 4. Nothing in stock; a store ----------------------------------------------------------------------------------------------

    public function test_nothing_in_stock_says_so_and_a_store_is_blank(): void
    {
        $this->batch($this->strain('Vacía'), $this->norte, 1000, remainingCg: 0);
        $store = Location::factory()->create(['organisation_id' => $this->org->id, 'kind' => LocationKind::ALMACEN, 'capacity' => null]);

        Livewire::test(ListLocations::class)
            ->assertTableColumnStateSet('prices_gap', __('Sin existencias'), $this->norte)
            ->assertTableColumnStateSet('prices_gap', null, $store);
    }

    // --- 5. Bounded queries --------------------------------------------------------------------------------------------------------

    public function test_the_sedes_table_does_not_query_per_strain_or_batch(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListLocations::class);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->batch($this->strain('Uno'), $this->norte, 1000);
        $few = $count();

        foreach (range(1, 8) as $i) {
            $genetic = $this->strain('Variedad '.$i);
            $this->batch($genetic, $this->norte, $i % 2 === 0 ? 1000 : null);
            $this->batch($genetic, $this->centro, 1000);
        }
        $many = $count();

        $this->assertLessThanOrEqual($few, $many, 'the Sedes table must not query per strain or per batch');
    }

    // --- 6. The strains list, scoped to the chosen sede ---------------------------------------------------------------------------

    public function test_a_strain_priced_only_at_centro_reads_sin_precio_with_norte_selected(): void
    {
        $genetic = $this->strain('Purple Haze');
        $this->batch($genetic, $this->centro, 1000);
        $this->batch($genetic, $this->norte, null);

        app(ActiveScope::class)->setLocation($this->norte->id);
        Livewire::test(ListGenetics::class)->assertTableColumnStateSet('completeness', __('Sin precio'), $genetic);

        app(ActiveScope::class)->setLocation($this->centro->id);
        Livewire::test(ListGenetics::class)->assertTableColumnStateSet('completeness', __('Lista'), $genetic);

        app(ActiveScope::class)->setLocation(null);
        Livewire::test(ListGenetics::class)->assertTableColumnStateSet('completeness', __('Lista'), $genetic);
    }
}
