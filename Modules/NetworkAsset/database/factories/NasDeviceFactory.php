<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;

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
            'nasname' => fn (array $attributes) => NetworkAsset::withoutCompany()->whereKey($attributes['network_asset_id'])->value('management_ip'),
            'coa_port' => 1700,
            'radius_secret' => 'fake-radius-shared-secret',
        ];
    }
}
