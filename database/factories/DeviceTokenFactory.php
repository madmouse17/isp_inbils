<?php

namespace Database\Factories;

use App\Models\Core\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceToken>
 */
class DeviceTokenFactory extends Factory
{
    protected $model = DeviceToken::class;

    /** A row bound to a fresh Sanctum token of its user for its app, the way a registration binds it. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'app' => 'technician',
            'personal_access_token_id' => fn (array $attributes): int => User::query()
                ->findOrFail($attributes['user_id'])
                ->createToken('Test phone', [$attributes['app']], now()->addDays(30))
                ->accessToken->getKey(),
            'platform' => 'android',
            'token' => Str::random(152),
            'device_name' => 'Test phone',
            'last_seen_at' => now(),
        ];
    }

    public function forApp(string $app): static
    {
        return $this->state(['app' => $app]);
    }
}
