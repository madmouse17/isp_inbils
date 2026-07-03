<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeProfile> */
class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'user_id' => User::factory(),
            'organization_id' => null,
            'vehicle_id' => null,
            'employee_number' => 'EMP-' . str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'phone' => fake()->optional()->phoneNumber(),
            'hire_date' => fake()->optional()->dateTimeBetween('-5 years', 'now')?->format('Y-m-d'),
            'status' => 'active',
            'skills' => fake()->optional()->randomElements(['fiber', 'OLT', 'wireless', 'router', 'switch'], 2),
        ];
    }
}
