<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\OrganizationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationUnit> */
class OrganizationUnitFactory extends Factory
{
    protected $model = OrganizationUnit::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'parent_id' => null,
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->company(),
            'type' => fake()->randomElement(['company', 'branch', 'area', 'unit', 'team']),
            'is_active' => true,
        ];
    }
}
