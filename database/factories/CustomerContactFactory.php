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
            'role' => fake()->optional()->jobTitle(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'is_primary' => false,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CustomerContact $contact): void {
            if ($contact->company_id) {
                return;
            }

            $customer = $contact->relationLoaded('customer')
                ? $contact->customer
                : Customer::query()->find($contact->customer_id);

            // If customer_id is still a factory instance unresolved, create it now.
            if (! $customer && $contact->customer_id) {
                return;
            }

            if ($customer) {
                $contact->company_id = $customer->company_id;
            }
        });
    }
}
