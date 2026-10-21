<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Vehicle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
        $this->grantRole($this->admin, 'admin');
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

    public function test_index_gives_the_purchase_date_as_a_plain_date_so_the_edit_form_can_preload_it(): void
    {
        Vehicle::factory()->create([
            'company_id' => $this->admin->company_id,
            'purchase_date' => '2025-11-30',
        ]);

        $this->actingAs($this->admin)->get(route('admin.vehicles.index'))
            ->assertInertia(fn (Assert $page) => $page->where('vehicles.data.0.purchase_date', '2025-11-30'));
    }

    public function test_index_is_paginated_so_the_page_can_show_its_controls(): void
    {
        Vehicle::factory()->count(12)->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->get(route('admin.vehicles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vehicles.data', 10)
                ->where('vehicles.meta.total', 12)
                ->where('vehicles.meta.last_page', 2));

        $this->actingAs($this->admin)->get(route('admin.vehicles.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('vehicles.data', 2));
    }

    public function test_update_saves_the_purchase_date_the_form_preloaded(): void
    {
        $vehicle = Vehicle::factory()->create(['company_id' => $this->admin->company_id, 'plate_number' => 'B 1 AA']);

        $this->actingAs($this->admin)->put(route('admin.vehicles.update', $vehicle), [
            'plate_number' => 'B 1 AA',
            'purchase_date' => '2025-11-30',
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2025-11-30', $vehicle->fresh()->purchase_date->toDateString());
    }

    public function test_purchase_date_must_be_a_plain_date(): void
    {
        $this->actingAs($this->admin)->post(route('admin.vehicles.store'), [
            'plate_number' => 'B 9 ZZ',
            'purchase_date' => '30/11/2025',
        ])->assertSessionHasErrors(['purchase_date' => 'tanggal pembelian harus sesuai format Y-m-d.']);

        $this->assertDatabaseMissing('vehicles', ['plate_number' => 'B 9 ZZ']);
    }

    public function test_delete_removes_a_vehicle_nobody_drives_for_good(): void
    {
        $vehicle = Vehicle::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->delete(route('admin.vehicles.destroy', $vehicle))
            ->assertRedirect()
            ->assertSessionHas('success', 'Kendaraan dihapus permanen.');

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }

    public function test_delete_is_refused_while_an_employee_is_assigned_the_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create(['company_id' => $this->admin->company_id]);
        $employee = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id])->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $this->actingAs($this->admin)->deleteJson(route('admin.vehicles.destroy', $vehicle))
            ->assertUnprocessable()
            ->assertJsonPath('errors.lifecycle.0', 'Kendaraan masih dipakai 1 karyawan. Nonaktifkan saja.');

        $this->assertNotSoftDeleted($vehicle);
        $this->assertSame($vehicle->id, $employee->fresh()->vehicle_id);
    }

    public function test_the_plate_of_a_deleted_vehicle_stays_taken(): void
    {
        Vehicle::factory()->create(['company_id' => $this->admin->company_id, 'plate_number' => 'B 1 OLD'])->delete();

        $this->actingAs($this->admin)->post(route('admin.vehicles.store'), [
            'plate_number' => 'B 1 OLD',
            'is_active' => true,
        ])->assertSessionHasErrors(['plate_number']);
    }
}
