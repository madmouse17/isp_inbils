<?php

namespace Database\Factories;

use App\Models\Core\Customer;
use App\Models\Core\CustomerContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerContact>
 */
class CustomerContactFactory extends Factory
{
    protected $model = CustomerContact::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->name(),
            'position' => fake()->optional()->jobTitle(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional()->email(),
            'is_primary' => false,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
