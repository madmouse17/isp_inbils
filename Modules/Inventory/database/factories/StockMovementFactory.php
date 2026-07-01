<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'product_id' => Product::factory(),
            'from_location_id' => null,
            'to_location_id' => Location::factory(),
            'movement_type' => 'receive',
            'quantity' => fake()->numberBetween(1, 100),
            'balance_after' => fake()->numberBetween(1, 100),
            'reserved_after' => 0,
            'reference_type' => null,
            'reference_id' => null,
            'note' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
