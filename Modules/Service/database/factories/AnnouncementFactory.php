<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\Announcement;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'kind' => 'info',
            'audience' => 'all',
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()]);
    }

    public function targeted(): static
    {
        return $this->state(['audience' => 'targeted']);
    }
}
