<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Vehicle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleServerTableExportTest extends TestCase
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
        ]);
        $this->admin->assignRole('admin');
    }

    private function makeVehicle(int $companyId, string $plate, string $brand = 'Toyota'): Vehicle
    {
        return Vehicle::query()->forceCreate([
            'company_id' => $companyId,
            'plate_number' => $plate,
            'type' => 'van',
            'brand' => $brand,
            'model' => 'Hiace',
            'is_active' => true,
        ]);
    }

    public function test_index_is_company_scoped_and_searchable(): void
    {
        $this->makeVehicle((int) $this->admin->company_id, 'B 1000 AA');
        $this->makeVehicle((int) $this->admin->company_id, 'B 2000 BB');
        $this->makeVehicle((int) $this->otherCompany->id, 'D 9999 ZZ');

        $this->actingAs($this->admin)
            ->get(route('admin.vehicles.index', ['search' => 'B 1000']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Vehicles/Index')
                ->has('vehicles.data', 1)
                ->where('vehicles.data.0.plate_number', 'B 1000 AA')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $this->makeVehicle((int) $this->admin->company_id, 'B 1000 AA', 'ExportBrand');
        $this->makeVehicle((int) $this->admin->company_id, 'B 2000 BB', 'OtherBrand');
        $this->makeVehicle((int) $this->otherCompany->id, 'D 9999 ZZ', 'ExportBrand');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.vehicles.export', [
                'format' => 'csv',
                'search' => 'ExportBrand',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('B 1000 AA', $content);
        $this->assertStringNotContainsString('B 2000 BB', $content);
        $this->assertStringNotContainsString('D 9999 ZZ', $content);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'vehicles-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('vehicles.view', 'web');
        $role->syncPermissions(['vehicles.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.vehicles.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
