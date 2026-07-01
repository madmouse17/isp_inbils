<?php

namespace Modules\SPK\Database\Factories;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SPK\Models\WorkOrder;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'code' => 'SPK-' . now()->year . '-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'type' => fake()->randomElement(['installation', 'maintenance', 'upgrade_service', 'relocation']),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'status' => 'draft',
            'source' => 'manual',
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'urgent']),
            'scheduled_date' => fake()->optional()->dateTimeBetween('now', '+30 days')?->format('Y-m-d'),
            'created_by' => User::factory(),
        ];
    }
}
