<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_admin_pages_render_without_redirects(): void
    {
        $user = $this->adminUser();
        $this->actingAs($user);
        CompanyService::resetCache();

        $customer = Customer::query()->create([
            'code' => 'CUST-001',
            'name' => 'Smoke Customer',
            'type' => 'Individual',
            'phone' => '08123456789',
            'is_active' => true,
        ]);
        $this->assertSame((int) $user->company_id, (int) $customer->company_id);

        foreach ([
            '/profile',
            '/admin/dashboard',
            '/admin/customers',
            "/admin/customers/{$customer->id}",
            '/admin/service-packages',
            '/admin/products',
            '/admin/network-assets',
            '/admin/spk',
            '/admin/invoices',
            '/admin/tickets',
            '/admin/reports',
        ] as $uri) {
            $response = $this->get($uri);

            $this->assertSame(200, $response->status(), $uri);
        }
    }

    public function test_legacy_dashboard_redirects_to_admin_dashboard(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/dashboard')
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    private function adminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $company = Company::withoutEvents(fn () => Company::query()->create([
            'name' => 'PT Smoke',
            'code' => 'SMOKE',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'settings' => [],
        ]));

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');

        return $user;
    }
}
