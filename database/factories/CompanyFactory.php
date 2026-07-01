<?php

namespace Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'is_active' => true,
            'settings' => [],
        ];
    }
}
