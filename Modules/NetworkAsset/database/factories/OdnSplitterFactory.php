<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\OdnSplitter;

/**
 * A raw 1:8 splitter row without its output ports; OdnService::createSplitter builds the real thing.
 *
 * @extends Factory<OdnSplitter>
 */
class OdnSplitterFactory extends Factory
{
    protected $model = OdnSplitter::class;

    public function definition(): array
    {
        return [
            'company_id' => CompanyService::currentId() ?? Company::factory(),
            'network_asset_id' => NetworkAssetFactory::new()->state(['asset_type' => 'odc']),
            'outputs' => 8,
            'input_port_id' => null,
            'label' => null,
        ];
    }
}
