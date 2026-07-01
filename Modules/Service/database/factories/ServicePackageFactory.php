<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;

/** @extends Factory<ServicePackage> */
class ServicePackageFactory extends Factory
{
    protected $model = ServicePackage::class;

    public function definition(): array
    {
        $companyId = Company::query()->value('id') ?? Company::query()->create(['name' => 'Test Company', 'code' => 'TEST'])->id;

        return [
            'company_id' => $companyId,
            'code' => strtoupper(fake()->unique()->bothify('PKG-###')),
            'name' => fake()->randomElement(['Home', 'Business', 'Enterprise']).' '.fake()->numberBetween(10, 200).'Mbps',
            'bandwidth_profile_id' => BandwidthProfile::query()->where('company_id', $companyId)->value('id') ?? BandwidthProfile::factory()->create(['company_id' => $companyId])->id,
            'speed_profile_id' => SpeedProfile::query()->where('company_id', $companyId)->value('id') ?? SpeedProfile::factory()->create(['company_id' => $companyId])->id,
            'sla_tier_id' => SLATier::query()->where('company_id', $companyId)->value('id') ?? SLATier::factory()->create(['company_id' => $companyId])->id,
            'price_mrc' => fake()->numberBetween(150000, 2000000),
            'price_otc' => fake()->numberBetween(0, 1000000),
            'contract_min_months' => fake()->randomElement([0, 12, 24]),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
