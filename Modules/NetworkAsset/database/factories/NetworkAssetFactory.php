<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\NetworkAsset;

/**
 * @extends Factory<NetworkAsset>
 */
class NetworkAssetFactory extends Factory
{
    protected $model = NetworkAsset::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'product_id' => null,
            'code' => 'AST-' . now()->year . '-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => fake()->words(2, true),
            'asset_type' => fake()->randomElement(['router', 'switch', 'olt', 'onu_ont', 'radio', 'antenna', 'fiber', 'odp', 'odc', 'rack', 'power', 'other']),
            'serial_number' => fake()->unique()->numerify('SN-##########'),
            'mac_address' => fake()->optional()->macAddress(),
            'ip_address' => fake()->optional()->ipv4(),
            'management_ip' => fake()->optional()->ipv4(),
            'location_id' => null,
            'customer_id' => null,
            'subscription_id' => null,
            'status' => 'available',
            'ownership' => fake()->randomElement(['owned', 'leased']),
            'vendor' => fake()->randomElement(['Huawei', 'Cisco', 'Mikrotik', 'Ubiquiti', 'TP-Link']),
            'model' => fake()->optional()->bothify('Model-####'),
            'purchase_date' => fake()->optional()->date(),
            'purchase_price' => fake()->optional()->numberBetween(500000, 50000000),
            'warranty_expiry' => fake()->optional()->date(),
            'notes' => fake()->optional()->sentence(),
            'installed_at' => null,
            'retired_at' => null,
        ];
    }
}
