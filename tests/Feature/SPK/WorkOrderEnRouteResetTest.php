<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Modules\SPK\Support\TripSteps;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * The trip, the start and the finish belong to one technician's one run of the work (spec F1, F2): a new technician, or a rework
 * after a rejection, starts all three over, so the customer is never told about a trip somebody else made and a reworked visit
 * never shows a start or a finish of its earlier run above a stepper that begins at "Ditugaskan" again.
 */
class WorkOrderEnRouteResetTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const LEFT_AT = '2026-10-16 01:30:00';

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->actingAs($this->admin);
    }

    public function test_a_new_technician_starts_the_trip_over(): void
    {
        $first = $this->technician();
        $second = $this->technician();
        $workOrder = $this->workOrder('assigned', $first, ['en_route_at' => self::LEFT_AT]);

        $reassigned = $this->assign($workOrder, $second, $first->id);

        $this->assertSame($second->id, $reassigned->assigned_to);
        $this->assertNull($reassigned->en_route_at);
        $this->assertSame('assigned', TripSteps::current($reassigned));
    }

    public function test_assigning_the_same_technician_again_while_assigned_keeps_the_trip(): void
    {
        $technician = $this->technician();
        $workOrder = $this->workOrder('assigned', $technician, ['en_route_at' => self::LEFT_AT]);

        $reassigned = $this->assign($workOrder, $technician, $technician->id);

        $this->assertSame(self::LEFT_AT, $reassigned->en_route_at->format('Y-m-d H:i:s'));
        $this->assertSame('en_route', TripSteps::current($reassigned));
    }

    public function test_a_rework_starts_every_step_over_even_for_the_same_technician(): void
    {
        $technician = $this->technician();
        $workOrder = $this->rejectedVisit($technician);

        $reassigned = $this->assign($workOrder, $technician, $technician->id);

        $this->assertSame('assigned', $reassigned->status);
        $this->assertNull($reassigned->en_route_at);
        $this->assertNull($reassigned->started_at);
        $this->assertNull($reassigned->submitted_at);
        $this->assertSame('assigned', TripSteps::current($reassigned));
    }

    public function test_a_rejected_visit_given_to_another_technician_starts_every_step_over(): void
    {
        $first = $this->technician();
        $second = $this->technician();
        $workOrder = $this->rejectedVisit($first);

        $reassigned = $this->assign($workOrder, $second, $first->id);

        $this->assertSame([$second->id, 'assigned'], [$reassigned->assigned_to, $reassigned->status]);
        $this->assertNull($reassigned->en_route_at);
        $this->assertNull($reassigned->started_at);
        $this->assertNull($reassigned->submitted_at);
        $this->assertSame('assigned', TripSteps::current($reassigned));
    }

    private function rejectedVisit(User $technician): WorkOrder
    {
        return $this->workOrder('rejected', $technician, [
            'en_route_at' => self::LEFT_AT,
            'started_at' => '2026-10-16 02:00:00',
            'submitted_at' => '2026-10-16 04:00:00',
            'rejected_at' => '2026-10-16 05:00:00',
            'rejection_reason' => 'Foto kurang jelas',
        ]);
    }

    private function technician(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $user->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function workOrder(string $status, User $technician, array $attributes = []): WorkOrder
    {
        return WorkOrder::forceCreate($attributes + [
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-0001',
            'type' => 'installation',
            'title' => 'Pasang ONT baru',
            'status' => $status,
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'assigned_to' => $technician->id,
        ]);
    }

    private function assign(WorkOrder $workOrder, User $technician, int $expectedAssignedTo): WorkOrder
    {
        $workOrder = $workOrder->fresh();

        return SpkService::assign($workOrder, $technician->id, $this->admin->id, $expectedAssignedTo, $workOrder->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }
}
