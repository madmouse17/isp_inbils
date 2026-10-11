<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\OdnPort;

/**
 * A raw PON port row; OdnService::createPonPorts and createSplitter build the real ones.
 *
 * @extends Factory<OdnPort>
 */
class OdnPortFactory extends Factory
{
    protected $model = OdnPort::class;

    public function definition(): array
    {
        return [
            'company_id' => CompanyService::currentId() ?? Company::factory(),
            'network_asset_id' => NetworkAssetFactory::new()->state(['asset_type' => 'olt']),
            'odn_splitter_id' => null,
            'kind' => OdnPort::KIND_PON,
            'number' => fake()->unique()->numberBetween(1, 60000),
            'label' => null,
            'subscription_id' => null,
        ];
    }
}
