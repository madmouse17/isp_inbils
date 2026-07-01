<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\SLATier;

/** @extends Factory<SLATier> */
class SLATierFactory extends Factory
{
    protected $model = SLATier::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::query()->create(['name' => 'Test Company', 'code' => 'TEST'])->id,
            'name' => fake()->randomElement(['Bronze', 'Silver', 'Gold']).' SLA',
            'uptime_pct' => fake()->randomElement([99.00, 99.50, 99.90]),
            'response_time_hours' => fake()->randomElement([4, 8, 24]),
            'resolution_time_hours' => fake()->randomElement([12, 24, 48]),
            'credit_pct' => fake()->randomElement([0, 5, 10]),
            'is_active' => true,
        ];
    }
}
