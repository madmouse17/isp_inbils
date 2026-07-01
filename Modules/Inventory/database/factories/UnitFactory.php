<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Unit;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'name' => fake()->unique()->word(),
            'symbol' => strtoupper(fake()->unique()->lexify('??')),
        ];
    }
}
