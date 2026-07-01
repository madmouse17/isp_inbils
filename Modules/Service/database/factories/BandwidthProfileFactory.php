<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\BandwidthProfile;

/** @extends Factory<BandwidthProfile> */
class BandwidthProfileFactory extends Factory
{
    protected $model = BandwidthProfile::class;

    public function definition(): array
    {
        $download = fake()->randomElement([10, 20, 50, 100, 200]);

        return [
            'company_id' => Company::query()->value('id') ?? Company::query()->create(['name' => 'Test Company', 'code' => 'TEST'])->id,
            'name' => $download.'M/'.max(5, (int) ($download / 5)).'M Shared 1:8',
            'download_mbps' => $download,
            'upload_mbps' => max(5, (int) ($download / 5)),
            'type' => 'shared',
            'contention_ratio' => 8,
            'is_active' => true,
        ];
    }
}
