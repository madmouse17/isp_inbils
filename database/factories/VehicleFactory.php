<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'plate_number' => fake()->unique()->numerify('B #### ??'),
            'type' => fake()->randomElement(['motorcycle', 'car', 'truck']),
            'brand' => fake()->optional()->word(),
            'model' => fake()->optional()->word(),
            'is_active' => true,
        ];
    }
}
