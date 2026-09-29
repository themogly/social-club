<?php

namespace Database\Factories;

use App\Enums\ConcentrateSubtype;
use App\Enums\CultivationType;
use App\Enums\ProductType;
use App\Models\Genetic;
use App\Models\Organisation;
use App\Support\EdibleEquivalence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Genetic>
 */
class GeneticFactory extends Factory
{
    protected $model = Genetic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->paragraph(),
            'category_id' => null,
            'product_type' => ProductType::FLOWER, // unit_type WEIGHT, derived by the observer
            'thc_bp' => fake()->numberBetween(0, 3000),
            'cbd_bp' => fake()->numberBetween(0, 2000),
            'terpenes' => [],
            'cultivation_type' => CultivationType::INDOOR,
            'images' => [],
            'published' => true,
            'active' => true,
        ];
    }

    /** A WEIGHT-type concentrate (hash by default) — priced and dispensed per gram, like flower. */
    public function concentrate(?ConcentrateSubtype $subtype = ConcentrateSubtype::ROSIN): static
    {
        return $this->state(fn (): array => [
            'product_type' => ProductType::CONCENTRATE,
            'concentrate_subtype' => $subtype,
        ]);
    }

    /** A UNIT-type preroll — a fixed gram content per unit (default 0.70 g = 70 cg). */
    public function preroll(int $gramsPerUnitCg = 70): static
    {
        return $this->state(fn (): array => [
            'product_type' => ProductType::PREROLL,
            'grams_per_unit_cg' => $gramsPerUnitCg,
        ]);
    }

    /** A UNIT-type edible, by its THC (mg) per unit — its grams are worked out from it (prompt 326, EdibleEquivalence). */
    public function edible(int $thcMgPerUnit = 10): static
    {
        return $this->state(fn (): array => [
            'product_type' => ProductType::EDIBLE,
            'thc_mg_per_unit' => $thcMgPerUnit,
            'grams_per_unit_cg' => EdibleEquivalence::gramsCg($thcMgPerUnit), // the observer derives it too
        ]);
    }
}
