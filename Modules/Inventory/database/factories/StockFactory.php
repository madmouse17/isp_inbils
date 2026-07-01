<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'product_id' => Product::factory(),
            'location_id' => Location::factory(),
            'quantity' => fake()->numberBetween(0, 500),
            'reserved_quantity' => 0,
        ];
    }
}
