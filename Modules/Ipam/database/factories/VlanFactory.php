<?php

namespace Modules\Ipam\Database\Factories;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Ipam\Models\Vlan;

/**
 * @extends Factory<Vlan>
 */
class VlanFactory extends Factory
{
    protected $model = Vlan::class;

    public function definition(): array
    {
        $vid = fake()->unique()->numberBetween(1, 4094);

        return [
            'company_id' => CompanyService::currentId() ?? Company::factory(),
            'location_id' => null,
            'vid' => $vid,
            'name' => 'VLAN '.$vid,
            'description' => null,
        ];
    }
}
