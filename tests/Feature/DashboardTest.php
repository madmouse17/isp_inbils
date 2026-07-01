<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $company = Company::factory()->create([
            'name' => 'Test ISP',
            'code' => 'TEST',
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_dashboard_returns_real_counts(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('company')
            ->where('userCount', 1)
            ->where('roleCount', 5)
            ->has('modules', 7)
        );
    }

    public function test_dashboard_shows_zero_customer_count_on_fresh_db(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('userCount', 1)
            ->has('modules', 7)
        );
        // Verify Customer module count is 0
        $modules = $response->inertiaProps()['modules'];
        $customerModule = collect($modules)->firstWhere('name', 'Customer');
        $this->assertNotNull($customerModule);
        $this->assertEquals(0, $customerModule['count']);
    }

    public function test_dashboard_company_info_displayed(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('company.name', 'Test ISP')
            ->where('company.code', 'TEST')
        );
    }
}
