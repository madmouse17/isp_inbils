<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The SPK page offers the assign dialog the people who can take the job. Only someone who may assign this SPK is sent
 * them, and each is a name, an employee number, a unit and a phone: never an email, a role, an HR note or a vehicle.
 */
class WorkOrderTechniciansPropTest extends TestCase
{
    use AsksInertiaForProps;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private User $admin;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->member('admin', 'Admin SPK');
        $this->technician = $this->member('technician', 'Teknisi Satu');

        $unit = OrganizationUnit::factory()->create(['company_id' => $this->company->id, 'name' => 'Tim Lapangan']);
        EmployeeProfile::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->technician->id,
            'organization_id' => $unit->id,
            'vehicle_id' => null,
            'employee_number' => 'EMP-0007',
            'phone' => '08123456789',
            'status' => 'active',
            'notes' => 'Catatan pribadi SDM',
            'skills' => ['fusion'],
        ]);
    }

    public function test_the_assign_dialog_gets_a_slim_list_of_the_technicians(): void
    {
        $workOrder = $this->workOrder('generated', null);

        $response = $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertOk();

        $this->assertSame(
            [['id' => $this->technician->id, 'name' => 'Teknisi Satu', 'employee_number' => 'EMP-0007', 'organization' => 'Tim Lapangan', 'phone' => '08123456789']],
            $response->inertiaProps('technicians'),
        );

        // Nothing else of the person is anywhere on the page.
        $page = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        foreach ([$this->technician->email, 'Catatan pribadi SDM', 'fusion'] as $private) {
            $this->assertStringNotContainsString($private, $page);
        }
    }

    public function test_someone_who_cannot_assign_the_spk_gets_no_technicians(): void
    {
        $workOrder = $this->workOrder('assigned', $this->technician->id);

        $response = $this->actingAs($this->technician)->get(route('admin.spk.show', $workOrder))->assertOk();

        $this->assertSame([], $response->inertiaProps('technicians'));
        $this->assertStringNotContainsString('EMP-0007', json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR));
    }

    public function test_reassigning_needs_the_reassign_permission(): void
    {
        $workOrder = $this->workOrder('assigned', $this->technician->id);
        $dispatcher = $this->member('staff', 'Petugas Dispatch');
        $dispatcher->givePermissionTo(['spk.view', 'spk.assign']);

        $this->assertSame([], $this->actingAs($dispatcher)->get(route('admin.spk.show', $workOrder))->assertOk()->inertiaProps('technicians'));

        $dispatcher->givePermissionTo('spk.reassign');
        app()['cache']->forget('spatie.permission.cache');

        $this->assertCount(1, $this->actingAs($dispatcher->fresh())->get(route('admin.spk.show', $workOrder))->assertOk()->inertiaProps('technicians'));
    }

    public function test_the_edit_page_follows_the_same_rule(): void
    {
        $workOrder = $this->workOrder('draft', null);

        // The edit page has no screen yet, so its props are read as the Inertia JSON a partial reload gets.
        $this->assertSame(
            [['id' => $this->technician->id, 'name' => 'Teknisi Satu', 'employee_number' => 'EMP-0007', 'organization' => 'Tim Lapangan', 'phone' => '08123456789']],
            $this->actingAs($this->admin)
                ->get(route('admin.spk.edit', $workOrder), $this->partialReload('Admin/SPK/Edit', 'technicians'))
                ->assertOk()
                ->json('props.technicians'),
        );

        $planner = $this->member('staff', 'Perencana');
        $planner->givePermissionTo(['spk.view', 'spk.update']);

        $this->assertSame([], $this->actingAs($planner->fresh())
            ->get(route('admin.spk.edit', $workOrder), $this->partialReload('Admin/SPK/Edit', 'technicians'))
            ->assertOk()
            ->json('props.technicians'));
    }

    private function member(string $role, string $name): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function workOrder(string $status, ?int $assignedTo): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => 'maintenance',
            'status' => $status,
            'assigned_to' => $assignedTo,
            'created_by' => $this->admin->id,
        ]);
    }
}
