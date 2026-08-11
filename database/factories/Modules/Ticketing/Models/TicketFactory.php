<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Ticketing\Models;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $category = TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'SLA',
            'code' => 'SLA-'.fake()->unique()->numberBetween(1, 99999),
            'default_sla_hours' => 2,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return [
            'company_id' => $company->id,
            'category_id' => $category->id,
            'code' => 'TCK-'.fake()->unique()->numberBetween(1, 99999),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'source' => 'customer',
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => User::factory()->create(['company_id' => $company->id])->id,
            'sla_deadline' => now()->addHours(2),
        ];
    }
}
