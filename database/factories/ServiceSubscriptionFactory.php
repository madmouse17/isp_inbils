<?php

namespace Database\Factories;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\ServicePackage;

/**
 * @extends Factory<ServiceSubscription>
 */
class ServiceSubscriptionFactory extends Factory
{
    protected $model = ServiceSubscription::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'service_package_id' => ServicePackage::factory(),
            'installation_address_id' => \App\Models\Core\CustomerAddress::factory(),
            'code' => 'SUB-' . now()->year . '-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => 'pending',
            'activation_date' => null,
            'expiration_date' => null,
            'billing_day' => fake()->numberBetween(1, 28),
            'next_invoice_date' => null,
            'ont_asset_id' => null,
            'serving_pop_id' => null,
            'mrc_amount' => fake()->numberBetween(50000, 500000),
            'otc_installation_fee' => fake()->numberBetween(0, 500000),
            'contract_months' => fake()->optional()->numberBetween(1, 24),
            'notes' => fake()->optional()->sentence(),
            'terminated_at' => null,
            'terminated_reason' => null,
        ];
    }
}
