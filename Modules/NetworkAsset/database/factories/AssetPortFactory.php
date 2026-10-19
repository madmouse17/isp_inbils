<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\AssetPort;

/**
 * A raw port row; AssetPanelService::apply builds the real ones from a layout.
 *
 * @extends Factory<AssetPort>
 */
class AssetPortFactory extends Factory
{
    protected $model = AssetPort::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 60000);

        return [
            'company_id' => CompanyService::currentId() ?? Company::factory(),
            'network_asset_id' => NetworkAssetFactory::new()->state(['asset_type' => 'router']),
            'group_key' => 'ether',
            'number' => $number,
            'kind' => 'rj45',
            'name' => 'ether'.$number,
            'label' => null,
            'note' => null,
            'admin_down' => false,
        ];
    }
}
