<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['Individual', 'Company']);

        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'code' => 'CUS-' . now()->year . '-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => $type === 'Company' ? fake()->company() : fake()->name(),
            'type' => $type,
            'email' => fake()->optional()->email(),
            'phone' => fake()->optional()->phoneNumber(),
            'tax_id' => $type === 'Company' ? fake()->numerify('##.###.###.#-###.###') : null,
            'contact_person' => $type === 'Company' ? fake()->name() : null,
            'area_coverage_id' => null,
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
