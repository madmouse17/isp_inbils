<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Category;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'parent_id' => null,
            'name' => fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
