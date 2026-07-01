<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_setup_with_no_company_renders_wizard(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)
            ->get('/setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Setup/Wizard'));
    }

    public function test_get_setup_with_company_exists_returns_403(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->get('/setup')->assertForbidden();
    }

    public function test_post_setup_valid_creates_company_assigns_admin_and_redirects(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['company_id' => null]);

        $response = $this->actingAs($user)->post('/setup', $this->validPayload());

        $response->assertRedirect('/dashboard');
        $company = Company::query()->firstOrFail();
        $this->assertSame('INBILS', $company->code);
        $this->assertSame($company->id, $user->refresh()->company_id);
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_post_setup_invalid_returns_422_with_errors(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)
            ->postJson('/setup', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code', 'currency', 'timezone', 'date_format', 'datetime_format']);
    }

    public function test_no_company_user_is_redirected_to_setup(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/setup');
    }

    public function test_admin_route_requires_company(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)->get('/admin')->assertRedirect('/setup');
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'PT Inbils Jaya',
            'code' => 'INBILS',
            'address' => 'Jl. Contoh No. 1',
            'phone' => '021123456',
            'email' => 'company@example.test',
            'website' => 'https://example.test',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y',
            'datetime_format' => 'd M Y H:i',
            'admin_name' => 'Owner Admin',
        ];
    }

    private function createCompany(): Company
    {
        return Company::withoutEvents(fn () => Company::query()->create([
            'name' => 'PT Existing',
            'code' => 'EXIST',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'settings' => [],
        ]));
    }
}
