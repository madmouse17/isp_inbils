<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SpkSelfApprovalGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'spk.approve';

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the permission exists (role-permission seeder is not run in test refresh)
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'spk.reject', 'guard_name' => 'web']);
    }

    /** @test */
    public function creator_cannot_approve_own_spk(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $user->givePermissionTo(self::PERMISSION);
        $this->grantPermissions($user, ['app.web'], $company);

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
            'assigned_to' => null,
        ]);

        $this->actingAs($user)
            ->post(route('admin.spk.approve', $workOrder))
            ->assertStatus(422);
    }

    /** @test */
    public function assigned_technician_cannot_approve_own_spk(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $user->givePermissionTo(self::PERMISSION);
        $this->grantPermissions($user, ['app.web'], $company);

        $creator = User::factory()->create(['company_id' => $company->id]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator->id,
            'assigned_to' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('admin.spk.approve', $workOrder))
            ->assertStatus(422);
    }

    /** @test */
    public function distinct_manager_can_approve(): void
    {
        [$company, $manager, $location, $customer] = $this->scope();
        $manager->givePermissionTo(self::PERMISSION);
        $this->grantPermissions($manager, ['app.web'], $company);

        $creator = User::factory()->create(['company_id' => $company->id]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'maintenance',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator->id,
            'assigned_to' => null,
        ]);
        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $company->id,
                'type' => 'photo',
                'uploaded_by' => $creator->id,
            ])
            ->toMediaCollection('evidence', 'public');

        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $workOrder))
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'approved_by' => $manager->id,
        ]);

        $wo = WorkOrder::withoutCompany()->findOrFail($workOrder->id);
        $this->assertNotNull($wo->approved_at);
    }

    /** @test */
    public function cross_tenant_approval_returns_404(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userA->givePermissionTo(self::PERMISSION);
        $this->grantPermissions($userA, ['app.web'], $companyA);

        $location = Location::create([
            'company_id' => $companyB->id,
            'code' => 'LOC-CROSS-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'Cross Tenant Location',
            'type' => 'site',
            'path' => 'Cross Tenant',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $companyB->id]);
        $creator = User::factory()->create(['company_id' => $companyB->id]);

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $companyB->id,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($userA)
            ->post(route('admin.spk.approve', $workOrder))
            ->assertStatus(404);
    }

    /** @test */
    public function creator_cannot_reject_own_spk(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $user->givePermissionTo('spk.reject');
        $this->grantPermissions($user, ['app.web'], $company);

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
            'assigned_to' => null,
        ]);

        $this->actingAs($user)
            ->post(route('admin.spk.reject', $workOrder), ['reason' => 'Self-reject'])
            ->assertStatus(422);
    }

    /** @test */
    public function assigned_technician_cannot_reject_own_spk(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $user->givePermissionTo('spk.reject');
        $this->grantPermissions($user, ['app.web'], $company);

        $creator = User::factory()->create(['company_id' => $company->id]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator->id,
            'assigned_to' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('admin.spk.reject', $workOrder), ['reason' => 'Self-reject'])
            ->assertStatus(422);
    }

    /** @test */
    public function reject_persists_rejected_by_and_rejected_at(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $user->givePermissionTo('spk.reject');
        $this->grantPermissions($user, ['app.web'], $company);

        $creator = User::factory()->create(['company_id' => $company->id]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($user)
            ->post(route('admin.spk.reject', $workOrder), ['reason' => 'Test rejection reason'])
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'rejected_by' => $user->id,
            'rejection_reason' => 'Test rejection reason',
        ]);

        $wo = WorkOrder::withoutCompany()->findOrFail($workOrder->id);
        $this->assertNotNull($wo->rejected_at);
    }

    private function scope(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-SPK-GUARD-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'SPK Guard Test Location',
            'type' => 'site',
            'path' => 'SPK Guard Test Location',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user, $location, $customer];
    }
}
