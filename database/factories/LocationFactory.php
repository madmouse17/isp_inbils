<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('LOC-###'));

        return [
            'company_id' => Company::query()->value('id') ?? Company::query()->create([
                'name' => 'Test Company',
                'code' => 'TEST',
                'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR',
                'is_active' => true,
            ])->id,
            'parent_id' => null,
            'code' => $code,
            'name' => fake()->city(),
            'type' => 'region',
            'path' => $code,
            'is_active' => true,
        ];
    }
}
