<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The SPK page offers only what the person can use on this SPK in this status, so a refusal is never the way to find out:
 * "Mulai" for the technician it is assigned to, "Batalkan" until the SPK is final, "Ganti teknisi" while it is assigned or
 * under way. The edit page of an SPK that is no longer a plan goes back to the SPK with the reason.
 */
class WorkOrderShowCapabilitiesTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    private User $technician;

    private User $planner;

    private Company $company;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        Notification::fake();
        $this->technician = $this->technicianLogin($this->company);
        $this->planner = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'email_verified_at' => now()]);
        $this->planner->givePermissionTo(['spk.view', 'spk.assign']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function spk(string $status, bool $assigned = true, array $attributes = []): WorkOrder
    {
        return WorkOrder::forceCreate($attributes + [
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT),
            'type' => 'maintenance',
            'title' => 'Ganti kabel',
            'status' => $status,
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'assigned_to' => $assigned ? $this->technician->id : null,
        ]);
    }

    /** @return array{start: bool, cancel: bool, reassign: bool} */
    private function can(User $user, WorkOrder $workOrder): array
    {
        app()['cache']->forget('spatie.permission.cache');

        return Arr::only($this->actingAs($user)->get(route('admin.spk.show', $workOrder))->assertOk()->inertiaProps('can'), ['start', 'cancel', 'reassign']);
    }

    public function test_can_flags(): void
    {
        $assigned = $this->spk('assigned');
        $this->assertSame(['start' => false, 'cancel' => true, 'reassign' => true], $this->can($this->admin, $assigned), 'the office may cancel and hand over, only the technician starts');
        $this->assertSame(['start' => true, 'cancel' => false, 'reassign' => false], $this->can($this->technician, $assigned), 'the technician starts, nothing else');
        $this->assertSame(['start' => false, 'cancel' => false, 'reassign' => false], $this->can($this->planner, $assigned), 'assign is not reassign, and nobody here may cancel');

        $this->assertSame(['start' => false, 'cancel' => true, 'reassign' => false], $this->can($this->admin, $this->spk('draft', assigned: false)), 'nobody to replace yet');
        $this->assertSame(['start' => false, 'cancel' => true, 'reassign' => true], $this->can($this->admin, $inProgress = $this->spk('in_progress')));
        $this->assertSame(['start' => false, 'cancel' => false, 'reassign' => false], $this->can($this->technician, $inProgress), 'started already');
        $this->assertSame(['start' => false, 'cancel' => true, 'reassign' => false], $this->can($this->admin, $this->spk('waiting_review')), 'in the reviewer\'s hands');
        $this->assertSame(['start' => false, 'cancel' => true, 'reassign' => false], $this->can($this->admin, $this->spk('rejected')), 'it is assigned again, not reassigned');
        $this->assertSame(['start' => false, 'cancel' => false, 'reassign' => false], $this->can($this->admin, $this->spk('completed')));
        $this->assertSame(['start' => false, 'cancel' => false, 'reassign' => false], $this->can($this->admin, $this->spk('cancelled')));
    }

    public function test_the_flags_follow_the_permissions_of_the_person_not_the_status_alone(): void
    {
        $assigned = $this->spk('assigned');
        $this->planner->givePermissionTo(['spk.reassign', 'spk.cancel']);

        $this->assertSame(['start' => false, 'cancel' => true, 'reassign' => true], $this->can($this->planner->fresh(), $assigned));
    }

    public function test_every_status_that_offers_ganti_teknisi_is_accepted_by_assign(): void
    {
        $replacement = $this->technicianLogin($this->company);
        $this->assertEqualsCanonicalizing(SpkService::REASSIGNABLE_STATUSES, array_values(array_intersect(SpkService::ASSIGNABLE_STATUSES, SpkService::REASSIGNABLE_STATUSES)));

        foreach (SpkService::REASSIGNABLE_STATUSES as $status) {
            $workOrder = $this->spk($status);
            $this->assertTrue($this->can($this->admin, $workOrder)['reassign'], $status);
            $fresh = $workOrder->fresh();

            $this->actingAs($this->admin)->post(route('admin.spk.assign', $workOrder), [
                'technician_id' => $replacement->public_id,
                'expected_assigned_to' => $this->technician->public_id,
                'expected_updated_at' => $fresh->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])->assertRedirect();

            $this->assertSame(['assigned', $replacement->id], [$workOrder->fresh()->status, $workOrder->fresh()->assigned_to], $status);
        }
    }

    public function test_naming_the_same_technician_keeps_a_running_visit_in_progress(): void
    {
        $workOrder = $this->spk('in_progress', attributes: ['en_route_at' => now()->subHours(2), 'started_at' => now()->subHour()]);
        $before = $workOrder->fresh();
        $this->actingAs($this->admin);

        $after = SpkService::assign(
            $before,
            $this->technician->id,
            $this->admin->id,
            $this->technician->id,
            $before->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        );

        $this->assertSame('in_progress', $after->status);
        $this->assertEquals($before->en_route_at, $after->en_route_at);
        $this->assertEquals($before->started_at, $after->started_at);
    }

    public function test_a_running_visit_still_needs_the_assignment_state_shown_to_the_dispatcher(): void
    {
        $workOrder = $this->spk('in_progress')->fresh();
        $this->actingAs($this->admin);

        try {
            SpkService::assign(
                $workOrder,
                $this->technicianLogin($this->company)->id,
                $this->admin->id,
                null,
                $workOrder->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            );
            $this->fail('the stale assignment state must be refused');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Konflik penugasan: SPK ini sudah ditugaskan oleh pengguna lain.', $exception->getMessage());
        }

        $this->assertSame(['in_progress', $this->technician->id], [$workOrder->fresh()->status, $workOrder->fresh()->assigned_to]);
    }

    public function test_edit_of_assigned_spk_redirects_with_message(): void
    {
        foreach (['assigned', 'in_progress', 'waiting_review', 'rejected', 'completed', 'cancelled'] as $status) {
            $workOrder = $this->spk($status);

            $this->actingAs($this->admin)->get(route('admin.spk.edit', $workOrder))
                ->assertRedirect(route('admin.spk.show', $workOrder))
                ->assertSessionHas('error', 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.');
        }
    }

    public function test_the_edit_page_still_asks_for_the_permission_before_it_says_anything_of_the_status(): void
    {
        $workOrder = $this->spk('assigned');

        $this->actingAs($this->planner)->get(route('admin.spk.edit', $workOrder))->assertForbidden();
    }
}
