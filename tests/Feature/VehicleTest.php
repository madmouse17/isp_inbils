<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Vehicle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_create_vehicle(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.vehicles.store'), [
            'plate_number' => 'B 1234 ABC',
            'type' => 'motorcycle',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vehicles', ['plate_number' => 'B 1234 ABC']);
    }

    public function test_plate_unique_per_company(): void
    {
        Vehicle::factory()->create([
            'company_id' => $this->admin->company_id,
            'plate_number' => 'DUP-PLATE',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.vehicles.store'), [
            'plate_number' => 'DUP-PLATE',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['plate_number']);
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.vehicles.index'));
        $response->assertOk();
    }
}
