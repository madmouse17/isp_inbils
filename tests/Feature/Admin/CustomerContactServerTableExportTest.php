<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerContact;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerContactServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

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

        $this->customer = Customer::factory()->create([
            'company_id' => $company->id,
        ]);
    }

    public function test_index_searches_contacts(): void
    {
        CustomerContact::factory()->create([
            'company_id' => $this->customer->company_id,
            'customer_id' => $this->customer->id,
            'name' => 'John Export',
        ]);
        CustomerContact::factory()->create([
            'company_id' => $this->customer->company_id,
            'customer_id' => $this->customer->id,
            'name' => 'Jane Skip',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.customers.contacts.index', [
                'customer' => $this->customer->id,
                'search' => 'John',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CustomerContacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'John Export')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_streams_filtered_data(): void
    {
        CustomerContact::factory()->create([
            'company_id' => $this->customer->company_id,
            'customer_id' => $this->customer->id,
            'name' => 'Contact Export One',
        ]);
        CustomerContact::factory()->create([
            'company_id' => $this->customer->company_id,
            'customer_id' => $this->customer->id,
            'name' => 'Contact Skip One',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.customers.contacts.export', [
                'customer' => $this->customer->id,
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Contact Export One', $content);
        $this->assertStringNotContainsString('Contact Skip One', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        CustomerContact::factory()->create([
            'company_id' => $this->customer->company_id,
            'customer_id' => $this->customer->id,
            'name' => 'Contact PDF',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.customers.contacts.export', [
                'customer' => $this->customer->id,
                'format' => 'pdf',
            ]));

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
        $role = Role::firstOrCreate(['name' => 'contact-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('customer.view', 'web');
        $role->syncPermissions(['customer.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.customers.contacts.export', [
                'customer' => $this->customer->id,
                'format' => 'csv',
            ]))
            ->assertForbidden();
    }
}
