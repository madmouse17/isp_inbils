<?php

namespace Modules\Service\Database\Factories;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Service\Models\ServicePackageCategory;

/** @extends Factory<ServicePackageCategory> */
class ServicePackageCategoryFactory extends Factory
{
    protected $model = ServicePackageCategory::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::query()->create(['name' => 'Test Company', 'code' => 'TEST'])->id,
            'code' => strtoupper(fake()->unique()->bothify('CAT-###')),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
