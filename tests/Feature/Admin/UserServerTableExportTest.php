<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
            'name' => 'Admin Owner',
            'email' => 'admin-owner@example.test',
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_index_is_company_scoped_and_filterable(): void
    {
        User::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Alpha Sort',
            'email' => 'alpha@example.test',
            'is_active' => true,
        ]);
        User::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Beta Sort',
            'email' => 'beta@example.test',
            'is_active' => false,
        ]);
        User::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Co User',
            'email' => 'other@example.test',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', [
                'search' => 'Sort',
                'is_active' => '1',
                'sort' => 'name',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.email', 'alpha@example.test')
                ->where('filters.search', 'Sort')
                ->where('filters.is_active', '1')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'desc')
            );
    }

    public function test_create_show_and_edit_render_inertia_pages(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Create'));

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Show'));

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Edit'));
    }

    public function test_show_includes_company_scoped_customer_and_employee_history(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id]);
        $customer = Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => $user->id,
        ]);
        $category = TicketCategory::forceCreate([
            'company_id' => $this->admin->company_id,
            'name' => 'User History',
            'code' => 'USER-HISTORY',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        Invoice::forceCreate([
            'company_id' => $this->admin->company_id,
            'number' => 'INV-USER-HISTORY',
            'type' => 'recurring',
            'source' => 'manual',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'sent',
            'subtotal' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100000,
            'paid_amount' => 0,
            'created_by' => $this->admin->id,
        ]);
        Ticket::forceCreate([
            'company_id' => $this->admin->company_id,
            'code' => 'TKT-USER-HISTORY',
            'title' => 'Assigned user ticket',
            'source' => 'manual',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to' => $user->id,
            'created_by' => $this->admin->id,
        ]);
        WorkOrder::forceCreate([
            'company_id' => $this->admin->company_id,
            'code' => 'SPK-USER-HISTORY',
            'type' => 'maintenance',
            'title' => 'Assigned user SPK',
            'status' => 'generated',
            'assigned_to' => $user->id,
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $otherUser = User::factory()->create(['company_id' => $this->otherCompany->id]);
        $otherCustomer = Customer::factory()->create([
            'company_id' => $this->otherCompany->id,
            'user_id' => $otherUser->id,
        ]);
        Invoice::forceCreate([
            'company_id' => $this->otherCompany->id,
            'number' => 'INV-OTHER-COMPANY',
            'type' => 'recurring',
            'source' => 'manual',
            'customer_id' => $otherCustomer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'sent',
            'subtotal' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100000,
            'paid_amount' => 0,
            'created_by' => $otherUser->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.linked_customer.id', $customer->id)
                ->where('history.invoices.0.number', 'INV-USER-HISTORY')
                ->where('history.tickets.0.code', 'TKT-USER-HISTORY')
                ->where('history.work_orders.0.code', 'SPK-USER-HISTORY')
                ->has('history.invoices', 1)
                ->has('history.tickets', 1)
                ->has('history.work_orders', 1)
            );
    }

    public function test_show_hides_history_without_module_permissions(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('historyAccess.billing', false)
                ->where('historyAccess.tickets', false)
                ->where('historyAccess.spk', false)
                ->has('history.invoices', 0)
                ->has('history.tickets', 0)
                ->has('history.work_orders', 0)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        User::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Export Target',
            'email' => 'export-target@example.test',
            'is_active' => true,
        ]);
        User::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Skip Target',
            'email' => 'skip-target@example.test',
            'is_active' => false,
        ]);
        User::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Export Target',
            'email' => 'other-export@example.test',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.export', [
                'format' => 'csv',
                'search' => 'Export Target',
                'is_active' => '1',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('export-target@example.test', $content);
        $this->assertStringNotContainsString('skip-target@example.test', $content);
        $this->assertStringNotContainsString('other-export@example.test', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'user-view-only', 'guard_name' => 'web']);
        $role->syncPermissions([]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.users.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
