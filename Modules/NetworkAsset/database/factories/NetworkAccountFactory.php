<?php

namespace Modules\NetworkAsset\Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * Fake credentials only. Pass subscription_id and nas_device_id of one company when the test has several.
 *
 * @extends Factory<NetworkAccount>
 */
class NetworkAccountFactory extends Factory
{
    protected $model = NetworkAccount::class;

    private static int $accounts = 0;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'subscription_id' => fn (array $attributes) => ServiceSubscription::factory()->create(['company_id' => $attributes['company_id']])->id,
            'nas_device_id' => fn (array $attributes) => NasDevice::factory()->create(['company_id' => $attributes['company_id']])->id,
            'service_type' => 'pppoe',
            'username' => 'fake-user-'.(++self::$accounts),
            'password' => 'fake-subscriber-secret',
        ];
    }

    public function hotspot(): static
    {
        return $this->state(fn () => ['service_type' => 'hotspot']);
    }
}
