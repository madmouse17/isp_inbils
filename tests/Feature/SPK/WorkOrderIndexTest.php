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
                ->where('workOrders.data.0.id', $workOrder->public_id)
                ->where('workOrders.data.0.code', 'SPK-2026-0001')
            );
    }

    public function test_index_page_size_and_sort_match_the_filtered_export(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $codes = [];

        foreach (range(1, 11) as $number) {
            $code = sprintf('SPK-TABLE-%02d', $number);
            $codes[] = $code;
            WorkOrder::forceCreate([
                'company_id' => $this->admin->company_id,
                'code' => $code,
                'type' => 'installation',
                'title' => 'Table parity',
                'customer_id' => $customer->id,
                'status' => 'draft',
                'source' => 'manual',
                'priority' => 'medium',
                'created_by' => $this->admin->id,
            ]);
        }

        $params = ['search' => 'SPK-TABLE-', 'sort_by' => 'code', 'sort_dir' => 'desc', 'per_page' => 25];
        $expected = array_reverse($codes);

        $this->actingAs($this->admin)
            ->get(route('admin.spk.index', $params))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SPK/Index')
                ->has('workOrders.data', 11)
                ->where('workOrders.meta.per_page', 25)
                ->where('workOrders.meta.total', 11)
                ->where('workOrders.data', fn ($rows) => collect($rows)->pluck('code')->all() === $expected)
                ->where('filters.sort_by', 'code')
                ->where('filters.sort_dir', 'desc')
                ->where('filters.per_page', '25')
            );

        $response = $this->get(route('admin.spk.export', $params + ['format' => 'csv', 'page' => 2]))->assertOk();
        $lines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $rows = array_map(str_getcsv(...), array_slice($lines, 1));
        $this->assertSame($expected, array_column($rows, 0));
    }

    public function test_invalid_sort_and_page_size_preserve_default_order(): void
    {
        $orders = [];
        foreach (['2026-01-01 08:00:00', '2026-01-02 08:00:00'] as $index => $createdAt) {
            $orders[] = WorkOrder::forceCreate([
                'company_id' => $this->admin->company_id,
                'code' => 'SPK-DEFAULT-'.$index,
                'type' => 'installation',
                'title' => 'Default order',
                'status' => 'draft',
                'source' => 'manual',
                'priority' => 'medium',
                'created_by' => $this->admin->id,
                'created_at' => $createdAt,
            ]);
        }

        $params = ['sort_by' => 'not_a_column', 'per_page' => 1000];
        $this->actingAs($this->admin)->get(route('admin.spk.index', $params))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workOrders.meta.per_page', 10)
                ->where('workOrders.data.0.id', $orders[1]->public_id)
                ->where('workOrders.data.1.id', $orders[0]->public_id)
            );

        $content = $this->get(route('admin.spk.export', $params + ['format' => 'csv']))
            ->assertOk()->streamedContent();
        $rows = array_map(str_getcsv(...), array_slice(preg_split('/\r\n|\n|\r/', trim($content)), 1));
        $this->assertSame([$orders[1]->code, $orders[0]->code], array_column($rows, 0));
    }

    public function test_export_is_forbidden_without_spk_view_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo('spk.export');

        $this->actingAs($user)
            ->get(route('admin.spk.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
