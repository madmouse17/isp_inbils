<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\NasDevice;

/**
 * Fake credentials and documentation-range addresses (RFC 5737) only: never a real router.
 *
 * @extends Factory<NasDevice>
 */
class NasDeviceFactory extends Factory
{
    protected $model = NasDevice::class;

    private static int $hosts = 0;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'network_asset_id' => fn (array $attributes) => NetworkAssetFactory::new([
                'company_id' => $attributes['company_id'],
                'asset_type' => 'router',
                'management_ip' => '192.0.2.'.(self::$hosts++ % 254 + 1),
            ])->create()->id,
            'api_port' => 443,
            'api_username' => 'inbils-api',
            'api_password' => 'fake-router-api-password',
            'isolir_profile' => 'isolir',
            'provisioning_enabled' => false,
        ];
    }

    /** Writes on: the router may be changed (the installation switch still decides first). */
    public function writable(): static
    {
        return $this->state(fn () => ['provisioning_enabled' => true]);
    }
}
