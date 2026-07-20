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

    public function configure(): static
    {
        return $this->afterMaking(function (CustomerAddress $address): void {
            if ($address->customer_id !== null && $address->getAttribute('company_id') === null) {
                $address->setAttribute(
                    'company_id',
                    Customer::withoutCompany()->whereKey($address->customer_id)->value('company_id')
                );
            }
        });
    }

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
