<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\SpeedProfile;

/** @extends Factory<SpeedProfile> */
class SpeedProfileFactory extends Factory
{
    protected $model = SpeedProfile::class;

    public function definition(): array
    {
        $speed = fake()->randomElement([10, 20, 50, 100, 200]);

        return [
            'company_id' => Company::query()->value('id') ?? Company::query()->create(['name' => 'Test Company', 'code' => 'TEST'])->id,
            'name' => 'Up to '.$speed.'Mbps',
            'download_max_mbps' => $speed,
            'upload_max_mbps' => max(5, (int) ($speed / 5)),
            'burst_download_mbps' => $speed * 2,
            'burst_upload_mbps' => max(10, (int) ($speed / 2)),
            'radius_profile_name' => 'home-'.$speed.'m',
            'is_active' => true,
        ];
    }
}
