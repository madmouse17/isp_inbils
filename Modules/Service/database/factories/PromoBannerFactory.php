<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\PromoBanner;

/** @extends Factory<PromoBanner> */
class PromoBannerFactory extends Factory
{
    protected $model = PromoBanner::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory(),
            'title' => fake()->sentence(4),
            'alt_text' => fake()->sentence(),
            'link_kind' => 'none',
            'link_target' => null,
            'audience' => 'all',
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'sort_order' => 0,
            'is_active' => true,
            'tap_count' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()]);
    }
}
