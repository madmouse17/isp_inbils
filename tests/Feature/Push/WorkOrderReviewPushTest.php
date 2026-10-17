<?php

namespace Tests\Feature\Push;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\DeviceToken;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Notifications\VisitCompletedNotification;
use Modules\SPK\Notifications\WorkOrderApprovedNotification;
use Modules\SPK\Notifications\WorkOrderRejectedNotification;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * A reviewer approves or rejects the SPK a technician submitted: the technician hears the verdict, and the customer hears
 * the visit is done, each in the bell and on the phone. Hooked where the status changes (SpkService), after commit.
 */
class WorkOrderReviewPushTest extends TestCase
{
    use CreatesCompanyUser;
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $reviewer;

    private User $creator;

    private User $technician;

    private User $customerUser;

    private Customer $customer;

    private ServiceSubscription $subscription;

    private int $workOrderSequence = 41;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
        $this->reviewer = $this->createCompanyUser();
        $company = Company::query()->findOrFail($this->reviewer->company_id);
        $this->creator = User::factory()->create(['company_id' => $company->id]);
        $this->technician = $this->technicianLogin($company);
        [$this->customerUser, $this->customer] = $this->customerLogin($company);
        $this->subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $this->customer->id])->id,
            'status' => 'active',
        ]);
        DeviceToken::factory()->create(['user_id' => $this->technician->id, 'app' => 'technician', 'token' => 'technician-install']);
        DeviceToken::factory()->forApp('customer')->create(['user_id' => $this->customerUser->id, 'token' => 'customer-install']);
    }

    /** @param array<string, mixed> $overrides */
    private function submitted(array $overrides = [], bool $withEvidence = true): WorkOrder
    {
        $workOrder = WorkOrderFactory::new()->create($overrides + [
            'company_id' => $this->reviewer->company_id,
            'code' => 'SPK-2026-'.str_pad((string) ++$this->workOrderSequence, 4, '0', STR_PAD_LEFT),
            'type' => 'maintenance',
            'status' => 'waiting_review',
            'created_by' => $this->creator->id,
            'assigned_to' => $this->technician->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
        ]);

        if ($withEvidence) {
            $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')
                ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $this->technician->id])
                ->toMediaCollection('evidence', 'public');
        }

        return $workOrder;
    }

    /** @return array<string, string> the data of the push that went to $token */
    private function pushTo(string $token): array
    {
        $pushes = array_values(array_filter($this->multicasts, fn (array $push): bool => $push['tokens'] === [$token]));
        $this->assertCount(1, $pushes, "one push to {$token}");

        return $pushes[0]['message']['data'];
    }

    public function test_an_approval_tells_the_technician_and_the_customer_once(): void
    {
        $workOrder = $this->submitted();

        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $workOrder))->assertRedirect();

        $technicianBell = $this->technician->notifications()->sole();
        $this->assertEqualsCanonicalizing(
            ['type' => 'spk_approved', 'work_order_id' => $workOrder->public_id, 'work_order_code' => 'SPK-2026-0042', 'company_id' => $workOrder->company_id],
            $technicianBell->data,
        );
        $customerBell = $this->customer->notifications()->sole();
        $this->assertEqualsCanonicalizing(['type' => 'visit_completed', 'work_order_id' => $workOrder->public_id, 'company_id' => $workOrder->company_id], $customerBell->data);

        $this->assertCount(2, $this->multicasts);
        $this->assertSame([
            'type' => 'spk_approved',
            'subject_type' => 'work_order',
            'subject_id' => $workOrder->public_id,
            'notification_id' => $technicianBell->id,
            'title' => 'SPK disetujui',
            'body' => 'SPK SPK-2026-0042 sudah disetujui.',
        ], $this->pushTo('technician-install'));
        $this->assertSame([
            'type' => 'visit_completed',
            'subject_type' => 'work_order',
            'subject_id' => $workOrder->public_id,
            'notification_id' => $customerBell->id,
            'title' => 'Kunjungan teknisi selesai',
            'body' => 'Kunjungan teknisi untuk layanan Anda sudah selesai.',
        ], $this->pushTo('customer-install'));

        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $workOrder))->assertStatus(422);
        $this->assertSame(1, $this->technician->notifications()->count(), 'a second approval is refused and tells nobody again');
    }

    public function test_a_rejection_tells_only_the_technician_and_carries_the_reason(): void
    {
        $workOrder = $this->submitted();

        $this->actingAs($this->reviewer)
            ->post(route('admin.spk.reject', $workOrder), ['reason' => 'Foto kabel kurang jelas, ulangi.'])
            ->assertRedirect();

        $bell = $this->technician->notifications()->sole();
        $this->assertEqualsCanonicalizing([
            'type' => 'spk_rejected',
            'work_order_id' => $workOrder->public_id,
            'work_order_code' => 'SPK-2026-0042',
            'reason' => 'Foto kabel kurang jelas, ulangi.',
            'company_id' => $workOrder->company_id,
        ], $bell->data);
        $this->assertSame(0, $this->customer->notifications()->count(), 'the customer is not told about internal rework');

        $this->assertCount(1, $this->multicasts);
        $this->assertSame('SPK ditolak', $this->pushTo('technician-install')['title']);
        $this->assertSame('SPK SPK-2026-0042 ditolak. Alasan: Foto kabel kurang jelas, ulangi.', $this->pushTo('technician-install')['body']);
    }

    public function test_a_long_reason_is_cut_to_what_a_lock_screen_shows(): void
    {
        $workOrder = $this->submitted();

        $this->actingAs($this->reviewer)->post(route('admin.spk.reject', $workOrder), ['reason' => str_repeat('x', 500)])->assertRedirect();

        $this->assertSame(200, mb_strlen($this->technician->notifications()->sole()->data['reason']));
    }

    public function test_the_customer_hears_of_the_visit_only_for_an_spk_of_their_own_subscription_and_when_they_have_a_login(): void
    {
        $unlinkedWorkOrder = $this->submitted(['subscription_id' => null, 'customer_id' => $this->customer->id]);
        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $unlinkedWorkOrder))->assertRedirect();
        $this->assertSame(0, $this->customer->notifications()->count(), 'the app lists subscription visits only');
        $this->assertSame(1, $this->technician->notifications()->count());

        $withoutLogin = Customer::factory()->create(['company_id' => $this->customer->company_id, 'user_id' => null]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $this->customer->company_id,
            'customer_id' => $withoutLogin->id,
            'service_package_id' => $this->subscription->service_package_id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $this->customer->company_id, 'customer_id' => $withoutLogin->id])->id,
            'status' => 'active',
        ]);
        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $this->submitted(['customer_id' => $withoutLogin->id, 'subscription_id' => $subscription->id])))->assertRedirect();
        $this->assertSame(0, $withoutLogin->notifications()->count());
    }

    public function test_a_review_that_fails_tells_nobody(): void
    {
        $workOrder = $this->submitted(withEvidence: false);

        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $workOrder))->assertStatus(422);

        $this->assertSame('waiting_review', $workOrder->fresh()->status);
        $this->assertSame(0, $this->technician->notifications()->count());
        $this->assertSame(0, $this->customer->notifications()->count());
        $this->assertSame([], $this->multicasts);
    }

    public function test_a_notification_that_cannot_be_sent_never_undoes_the_review(): void
    {
        $workOrder = $this->submitted();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('the queue is down'));

        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $workOrder))->assertRedirect();

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertNotNull($workOrder->fresh()->approved_at);
    }

    public function test_the_notifications_are_queued_and_carry_ids_a_code_and_at_most_the_reason(): void
    {
        Notification::fake();
        $workOrder = $this->submitted();

        $this->actingAs($this->reviewer)->post(route('admin.spk.approve', $workOrder))->assertRedirect();

        Notification::assertSentTo($this->technician, WorkOrderApprovedNotification::class, fn (WorkOrderApprovedNotification $notification): bool => $notification->workOrderId === $workOrder->id);
        Notification::assertSentTo($this->customer, VisitCompletedNotification::class);
        Notification::assertNotSentTo($this->technician, WorkOrderRejectedNotification::class);
    }
}
