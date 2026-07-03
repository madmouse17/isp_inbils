<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NumberSequence> */
class NumberSequenceFactory extends Factory
{
    protected $model = NumberSequence::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'entity_type' => fake()->unique()->word(),
            'prefix' => strtoupper(fake()->lexify('???')),
            'next_number' => 1,
            'padding' => 5,
            'year_suffix' => true,
        ];
    }
}
