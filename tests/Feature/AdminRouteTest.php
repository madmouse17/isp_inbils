<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_admin_dashboard_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));
        $response->assertOk();
    }

    public function test_admin_customers_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.customers.index'));
        $response->assertOk();
    }

    public function test_admin_products_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.index'));
        $response->assertOk();
    }

    public function test_admin_network_assets_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.network-assets.index'));
        $response->assertOk();
    }

    public function test_admin_spk_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.spk.index'));
        $response->assertOk();
    }

    public function test_admin_invoices_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.invoices.index'));
        $response->assertOk();
    }

    public function test_admin_tickets_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.tickets.index'));
        $response->assertOk();
    }

    public function test_admin_reports_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.reports.index'));
        $response->assertOk();
    }

    public function test_admin_evaluations_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.index'));
        $response->assertOk();
    }

    public function test_admin_users_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.users.index'));
        $response->assertOk();
    }

    public function test_unauthenticated_admin_route_redirects_to_login(): void
    {
        $response = $this->get(route('admin.dashboard'));
        $response->assertRedirect(route('login'));
    }
}
