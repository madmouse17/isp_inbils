<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Spec F7: the SPK page draws the same four steps as the apps from `steps`, and knows which one the visit is at.
 */
class WorkOrderStepsPropTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin');
    }

    public function test_the_page_gets_the_steps_and_the_one_the_visit_is_at(): void
    {
        $workOrder = $this->workOrder('assigned', ['en_route_at' => '2026-10-16 01:30:00']);

        $response = $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertOk();

        $steps = $response->inertiaProps('steps');
        $this->assertSame('en_route', $response->inertiaProps('currentStep'));
        $this->assertSame(['assigned', 'en_route', 'in_progress', 'installed'], array_column($steps, 'key'));
        $this->assertSame(['Ditugaskan', 'Dalam perjalanan', 'Sedang dikerjakan', 'Selesai dikerjakan'], array_column($steps, 'label'));
        $this->assertSame(
            [Carbon::parse('2026-10-16 01:00:00')->jsonSerialize(), Carbon::parse('2026-10-16 01:30:00')->jsonSerialize(), null, null],
            array_column($steps, 'at'),
        );
    }

    public function test_an_installation_ends_at_selesai_dipasang(): void
    {
        $workOrder = $this->workOrder('assigned', ['type' => 'installation']);

        $response = $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertOk();

        $this->assertSame('Selesai dipasang', array_column($response->inertiaProps('steps'), 'label')[3]);
    }

    public function test_a_finished_visit_has_reached_the_last_step(): void
    {
        $workOrder = $this->workOrder('waiting_review', [
            'en_route_at' => '2026-10-16 01:30:00',
            'started_at' => '2026-10-16 02:00:00',
            'submitted_at' => '2026-10-16 04:00:00',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertOk();

        $this->assertSame('installed', $response->inertiaProps('currentStep'));
        $this->assertNotContains(null, array_column($response->inertiaProps('steps'), 'at'));
    }

    public function test_a_draft_shows_four_steps_none_reached_and_a_cancelled_spk_shows_none(): void
    {
        $draft = $this->actingAs($this->admin)->get(route('admin.spk.show', $this->workOrder('draft')))->assertOk();
        $cancelled = $this->actingAs($this->admin)->get(route('admin.spk.show', $this->workOrder('cancelled', ['started_at' => '2026-10-16 02:00:00'])))->assertOk();

        $this->assertNull($draft->inertiaProps('currentStep'));
        $this->assertSame([null, null, null, null], array_column($draft->inertiaProps('steps'), 'at'));
        $this->assertNull($cancelled->inertiaProps('currentStep'));
        $this->assertSame([], $cancelled->inertiaProps('steps'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function workOrder(string $status, array $attributes = []): WorkOrder
    {
        $technician = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => 'maintenance',
            'status' => $status,
            'assigned_to' => in_array($status, ['draft'], true) ? null : $technician->id,
            'created_by' => $this->admin->id,
            ...$attributes,
        ]);

        if ($workOrder->assigned_to !== null) {
            WorkOrderAssignment::forceCreate([
                'company_id' => $this->company->id,
                'work_order_id' => $workOrder->id,
                'technician_id' => $technician->id,
                'assigned_by' => $this->admin->id,
                'assigned_at' => '2026-10-16 01:00:00',
            ]);
        }

        return $workOrder;
    }
}
