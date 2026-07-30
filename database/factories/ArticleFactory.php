<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => Location::factory(),
            'name' => fake()->words(2, true),
            'category_id' => null,
            'price_cents' => fake()->numberBetween(100, 5000),
            'stock' => fake()->numberBetween(0, 200),
            'low_stock_threshold' => fake()->numberBetween(1, 20),
            'images' => [],
            'active' => true,
        ];
    }
}
