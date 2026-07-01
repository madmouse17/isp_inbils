<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'sku' => 'PRD-' . strtoupper(fake()->unique()->bothify('??####')),
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
