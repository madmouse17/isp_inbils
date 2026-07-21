<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'category_id' => function (array $attributes) {
                $companyId = $attributes['company_id'] ?? Company::factory()->create()->id;

                return Category::factory()->create([
                    'company_id' => $companyId,
                ])->id;
            },
            'unit_id' => function (array $attributes) {
                $categoryId = $attributes['category_id'] ?? null;

                if ($categoryId) {
                    return Category::query()->find($categoryId)?->unit_id;
                }

                return null;
            },
            'sku' => 'PRD-'.strtoupper(fake()->unique()->bothify('??####')),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => fake()->numberBetween(1000, 500000),
            'cost_price' => fake()->numberBetween(500, 300000),
            'min_stock' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }
}
