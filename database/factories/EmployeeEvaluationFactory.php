<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEvaluation>
 */
class EmployeeEvaluationFactory extends Factory
{
    protected $model = EmployeeEvaluation::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'employee_id' => User::factory(),
            'reference_type' => fake()->randomElement(['WorkOrder', 'Ticket']),
            'reference_id' => fake()->numberBetween(1, 100),
            'score' => fake()->randomFloat(1, 3.0, 5.0),
            'customer_rating' => fake()->optional(0.3)->randomFloat(1, 1.0, 5.0),
            'first_response_minutes' => fake()->optional()->numberBetween(5, 480),
            'resolution_minutes' => fake()->optional()->numberBetween(30, 2880),
            'comment' => fake()->optional()->sentence(),
            'evaluator_id' => User::factory(),
            'evaluated_at' => now(),
        ];
    }
}
