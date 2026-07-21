<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Unit;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'parent_id' => null,
            'unit_id' => function (array $attributes) {
                $companyId = $attributes['company_id'] ?? Company::factory()->create()->id;

                return Unit::factory()->create([
                    'company_id' => $companyId,
                ])->id;
            },
            'name' => fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
