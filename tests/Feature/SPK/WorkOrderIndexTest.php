<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;

class WorkOrderIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_index_exposes_work_order_code_for_the_spk_table(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        $workOrder = WorkOrder::forceCreate([
            'company_id' => $this->admin->company_id,
            'code' => 'SPK-2026-0001',
            'type' => 'installation',
            'title' => 'Index contract test',
            'customer_id' => $customer->id,
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.spk.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SPK/Index')
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $workOrder->id)
                ->where('workOrders.data.0.code', 'SPK-2026-0001')
            );
    }
}
