<?php

namespace Tests\Feature\SPK;

use App\Http\Resources\NotificationResource;
use App\Models\Core\Company;
use App\Models\Core\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Notifications\WorkOrderSubmittedForReviewNotification;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * A technician submits an SPK and nobody was told it waits: the people who may approve now hear of it in the bell, and on the
 * phone when they have the technician app. Not the technician and not the person who made the SPK, who may not approve it.
 */
class WorkOrderSubmittedNoticeTest extends TestCase
{
    use CreatesCompanyUser;
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private Company $company;

    private User $technician;

    private User $creator;

    private User $approver;

    private WorkOrder $workOrder;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->fakeFirebase();
        $admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($admin->company_id);
        $this->technician = $this->technicianLogin($this->company, ['name' => 'Ani Teknisi']);
        $this->token = $this->issueToken($this->technician, 'technician');
        $this->creator = $this->member('Pembuat SPK', 'spk.approve');
        $this->approver = $this->member('Penyetuju Satu', 'spk.approve');
        $this->workOrder = $this->inProgress();
    }

    /** A user of the company holding exactly these permissions. */
    private function member(string $name, string ...$permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'email_verified_at' => now(), 'name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function inProgress(): WorkOrder
    {
        $workOrder = WorkOrder::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-0042',
            'type' => 'maintenance',
            'title' => 'Ganti kabel',
            'status' => 'in_progress',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->creator->id,
            'assigned_to' => $this->technician->id,
            'started_at' => now()->subHour(),
        ]);
        $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $this->technician->id])
            ->toMediaCollection('evidence', 'public');

        return $workOrder;
    }

    private function submit(): void
    {
        $this->api('POST', "technician/work-orders/{$this->workOrder->public_id}/submit", token: $this->token)->assertOk()->assertJsonPath('data.status', 'waiting_review');
    }

    public function test_submit_notifies_approvers_except_submitter(): void
    {
        Notification::fake();
        $second = $this->member('Penyetuju Dua', 'spk.approve');
        $this->technician->givePermissionTo('spk.approve');
        $nobody = $this->member('Tanpa Izin');
        $inactive = $this->member('Nonaktif', 'spk.approve');
        $inactive->forceFill(['is_active' => false])->save();
        $foreign = User::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'is_active' => true]);
        $foreign->givePermissionTo('spk.approve');

        $this->submit();

        Notification::assertSentTo(
            [$this->approver, $second],
            WorkOrderSubmittedForReviewNotification::class,
            fn (WorkOrderSubmittedForReviewNotification $notice): bool => [$notice->workOrderId, $notice->workOrderCode, $notice->technicianName, $notice->companyId]
                === [$this->workOrder->id, 'SPK-2026-0042', 'Ani Teknisi', $this->company->id],
        );
        Notification::assertNotSentTo([$this->technician, $this->creator, $nobody, $inactive, $foreign], WorkOrderSubmittedForReviewNotification::class);
    }

    public function test_the_bell_row_names_the_spk_and_the_technician(): void
    {
        $this->submit();

        $row = $this->approver->notifications()->sole();
        $this->assertEqualsCanonicalizing([
            'type' => 'spk_submitted',
            'work_order_id' => $this->workOrder->public_id,
            'work_order_code' => 'SPK-2026-0042',
            'technician_name' => 'Ani Teknisi',
            'company_id' => $this->company->id,
        ], $row->data);

        $bell = (new NotificationResource($row))->resolve(Request::create('/api/v1/notifications'));
        $this->assertSame('SPK menunggu review', $bell['title']);
        $this->assertSame('SPK-2026-0042 dari Ani Teknisi menunggu persetujuan.', $bell['message']);
        $this->assertSame(['type' => 'work_order', 'id' => $this->workOrder->public_id], $bell['subject']);
        $this->assertSame(0, $this->technician->notifications()->count(), 'the technician knows what they just did');
        $this->assertSame(0, $this->creator->notifications()->count());
    }

    public function test_an_approver_who_uses_the_technician_app_gets_a_push_too(): void
    {
        // Only someone the technician app lets in is pushed: a field supervisor who also approves. A manager without the app gets the bell.
        $supervisor = $this->technicianLogin($this->company, ['name' => 'Supervisor Lapangan']);
        $supervisor->givePermissionTo('spk.approve');
        DeviceToken::factory()->create(['user_id' => $supervisor->id, 'app' => 'technician', 'token' => 'supervisor-install']);
        DeviceToken::factory()->create(['user_id' => $this->approver->id, 'app' => 'technician', 'token' => 'manager-install']);

        $this->submit();

        $this->assertSame(['supervisor-install'], $this->pushedTokens());
        $this->assertSame('SPK menunggu review', $this->onlyPush()['data']['title']);
        $this->assertSame('spk_submitted', $this->onlyPush()['data']['type']);
        $this->assertSame($this->workOrder->public_id, $this->onlyPush()['data']['subject_id']);
        $this->assertSame(1, $supervisor->notifications()->count());
        $this->assertSame(1, $this->approver->notifications()->count(), 'the manager still has the bell');
    }

    public function test_a_refused_submit_notifies_nobody(): void
    {
        $this->workOrder->clearMediaCollection('evidence');

        $this->api('POST', "technician/work-orders/{$this->workOrder->public_id}/submit", token: $this->token)->assertUnprocessable();

        $this->assertSame('in_progress', $this->workOrder->fresh()->status);
        $this->assertSame(0, $this->approver->notifications()->count());
        $this->assertSame([], $this->multicasts);
    }

    public function test_a_notification_that_cannot_be_sent_never_undoes_the_submit(): void
    {
        Notification::shouldReceive('send')->andThrow(new RuntimeException('the queue is down'));

        $this->submit();

        $this->assertSame('waiting_review', $this->workOrder->fresh()->status);
    }
}
