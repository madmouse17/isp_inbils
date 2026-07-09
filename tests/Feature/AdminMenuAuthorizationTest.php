<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMenuAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_seeded_inventory_reader_can_open_sidebar_inventory_destinations(): void
    {
        $this->actingAs($this->userWithRole('technician'));

        foreach ([
            route('admin.stocks.index') => 'Admin/Inventory/Stocks/Index',
            route('admin.stock-movements.index') => 'Admin/Inventory/Movements/Index',
            route('admin.inventory.find') => 'Admin/Inventory/Find',
        ] as $uri => $component) {
            $this->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_seeded_report_reader_can_open_report_children(): void
    {
        $this->actingAs($this->userWithRole('staff'));

        foreach ([
            route('admin.reports.business') => 'Admin/Reports/Business',
            route('admin.reports.technician') => 'Admin/Reports/Technician',
            route('admin.reports.asset') => 'Admin/Reports/Asset',
            route('admin.reports.sla') => 'Admin/Reports/Sla',
            route('admin.reports.stock-card') => 'Admin/Reports/StockCard',
            route('admin.reports.audit-log') => 'Admin/Reports/AuditLog',
        ] as $uri => $component) {
            $this->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_user_without_inventory_or_report_permissions_gets_403(): void
    {
        $this->actingAs($this->userWithRole('customer'));

        foreach ([
            route('admin.stocks.index'),
            route('admin.stock-movements.index'),
            route('admin.inventory.find'),
            route('admin.reports.business'),
        ] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    private function userWithRole(string $roleName): User
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->assignRole(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
