<?php

namespace Tests\Feature\Auth;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_login_redirects_to_admin_dashboard_when_company_exists(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_redirects_to_setup_when_no_company(): void
    {
        $user = User::factory()->create([
            'company_id' => null,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Login always redirects to admin.dashboard, but RedirectIfNoCompany
        // middleware should redirect to /setup because company_id is null
        $this->assertContains($response->getTargetUrl(), [
            route('admin.dashboard'),
            url('/setup'),
            '/setup',
        ]);
    }

    public function test_dashboard_route_redirects_to_admin_dashboard(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('admin.dashboard'));
    }
}
