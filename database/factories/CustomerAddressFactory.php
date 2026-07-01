<?php

namespace Database\Factories;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => fake()->randomElement(['Rumah', 'Kantor', 'Cabang']),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'lat' => fake()->optional()->latitude(),
            'lng' => fake()->optional()->longitude(),
            'is_installation_point' => false,
            'is_primary' => false,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
