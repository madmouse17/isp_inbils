<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseDResourceSmokeTest extends TestCase
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
        $this->customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public static function resources(): array
    {
        return [
            'SLA tiers' => ['admin.sla-tiers.index', 'admin.sla-tiers.export', false],
            'speed profiles' => ['admin.speed-profiles.index', 'admin.speed-profiles.export', false],
            'bandwidth profiles' => ['admin.bandwidth-profiles.index', 'admin.bandwidth-profiles.export', false],
            'organizations' => ['admin.organizations.index', 'admin.organizations.export', false],
            'documents' => ['admin.documents.index', 'admin.documents.export', false],
            'customer contacts' => ['admin.customers.contacts.index', 'admin.customers.contacts.export', true],
            'SPK work orders' => ['admin.spk.index', 'admin.spk.export', false],
            'inventory stocks' => ['admin.stocks.index', 'admin.stocks.export', false],
            'inventory movements' => ['admin.stock-movements.index', 'admin.stock-movements.export', false],
            'customer addresses' => ['admin.customers.addresses.index', 'admin.customers.addresses.export', true],
        ];
    }

    #[DataProvider('resources')]
    public function test_admin_can_open_index_and_csv_export(
        string $indexRoute,
        string $exportRoute,
        bool $requiresCustomer,
    ): void {
        $this->assertTrue(Route::has($indexRoute), "Missing index route {$indexRoute}");
        $this->assertTrue(Route::has($exportRoute), "Missing export route {$exportRoute}");

        $parameters = $requiresCustomer ? [$this->customer] : [];

        $this->actingAs($this->admin)
            ->get(route($indexRoute, $parameters))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route($exportRoute, $parameters + ['format' => 'csv']))
            ->assertOk();
    }
}
