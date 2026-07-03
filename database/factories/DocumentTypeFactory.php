<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentType> */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'name' => fake()->words(2, true),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'applies_to' => fake()->randomElement(['Customer', 'Employee', 'Vehicle', 'WorkOrder', 'Ticket']),
            'is_required' => fake()->boolean(30),
            'expiry_days' => fake()->optional()->numberBetween(30, 365),
            'is_active' => true,
        ];
    }
}
