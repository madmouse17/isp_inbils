<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Notifications\WorkOrderScheduledNotification;
use Modules\SPK\Services\SpkService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithWhatsApp;

class WorkOrderWhatsAppNotificationTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->company->update(['name' => 'PT Contoh Net']);
        $this->whatsAppEnabledFor($this->company);
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $this->actingAs($this->admin);
        $this->travelTo('2026-10-14 03:00:00'); // 10:00 in Jakarta
    }

    /** @param array<string, mixed> $attributes */
    private function workOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::forceCreate($attributes + [
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-0001',
            'type' => 'installation',
            'title' => 'Pasang ONT baru',
            'status' => 'generated',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'customer_id' => $this->customer->id,
            'scheduled_date' => '2026-10-20',
        ]);
    }

    private function technician(string $name = 'Andi Wijaya', string $phone = '0813-5555-0001'): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'name' => $name]);
        $this->grantRole($user, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => $user->id, 'status' => 'active', 'phone' => $phone]);

        return $user;
    }

    private function assign(WorkOrder $wo, User $technician, ?int $expectedAssignedTo = null): WorkOrder
    {
        $wo = $wo->fresh();

        return SpkService::assign($wo, $technician->id, $this->admin->id, $expectedAssignedTo, $wo->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }

    /** @return array<string, list<string>> recipient => template parameters, in send order */
    private function sent(): array
    {
        return $this->graphRequests()->map(fn (Request $request): array => [
            $request->data()['to'].' '.$request->data()['template']['name'],
            array_column($request->data()['template']['components'][0]['parameters'], 'text'),
        ])->pipe(fn ($pairs) => $pairs->mapWithKeys(fn (array $pair): array => [$pair[0] => $pair[1]]))->all();
    }

    public function test_the_first_assignment_messages_the_technician_and_the_customer(): void
    {
        $wo = $this->workOrder();
        $technician = $this->technician('Andi Wijaya', '0813-5555-0001');

        $this->assign($wo, $technician);

        $this->assertSame([
            '6281355550001 spk_penugasan_teknisi' => ['SPK-2026-0001', 'Pasang ONT baru', '20 Okt 2026', route('admin.spk.show', $wo)],
            '6281234567890 spk_jadwal_pelanggan' => ['PT Contoh Net', 'SPK-2026-0001', '20 Okt 2026', 'Andi'],
        ], $this->sent());

        $rows = $this->whatsAppRows();
        $this->assertSame(['spk_scheduled:'.$wo->id.':'.$technician->id, 'spk_scheduled_customer:'.$wo->id], $rows->pluck('dedupe_key')->all());
        $this->assertSame(['spk_scheduled', 'spk_scheduled_customer'], $rows->pluck('event')->all());
        $this->assertSame(['user', 'customer'], $rows->pluck('recipient_type')->all());
        $this->assertSame([$technician->id, $this->customer->id], $rows->pluck('recipient_id')->all());
        $this->assertSame(['work_order', 'work_order'], $rows->pluck('subject_type')->all());
        $this->assertSame([$wo->id, $wo->id], $rows->pluck('subject_id')->all());
    }

    public function test_the_technician_message_carries_no_customer_data_and_the_customer_message_no_technician_phone(): void
    {
        $this->customer->forceFill(['name' => 'Ibu Siti Aminah'])->save();
        $wo = $this->workOrder();

        $this->assign($wo, $this->technician('Andi Wijaya', '0813-5555-0001'));

        $toTechnician = $this->graphRequests()->first(fn (Request $request): bool => $request->data()['to'] === '6281355550001')->data();
        $toCustomer = $this->graphRequests()->first(fn (Request $request): bool => $request->data()['to'] === '6281234567890')->data();
        foreach (['0812', '81234567890', 'Siti', $this->customer->code] as $customerData) {
            $this->assertStringNotContainsString($customerData, json_encode($toTechnician['template']));
        }
        foreach (['0813', '81355550001', 'Wijaya'] as $technicianData) {
            $this->assertStringNotContainsString($technicianData, json_encode($toCustomer['template']));
        }
    }

    public function test_reassigning_to_another_technician_messages_only_the_new_technician(): void
    {
        $wo = $this->workOrder();
        $first = $this->technician('Andi Wijaya', '0813-5555-0001');
        $second = $this->technician('Rina Putri', '0813-5555-0002');
        $this->assign($wo, $first);

        $this->assign($wo, $second, $first->id);

        $this->assertCount(3, $this->graphRequests());
        $this->assertSame(['6281355550001 spk_penugasan_teknisi', '6281234567890 spk_jadwal_pelanggan', '6281355550002 spk_penugasan_teknisi'], array_keys($this->sent()));
    }

    public function test_assigning_the_same_technician_again_sends_nothing(): void
    {
        $wo = $this->workOrder();
        $technician = $this->technician();
        $this->assign($wo, $technician);

        $this->assign($wo, $technician, $technician->id);

        $this->assertCount(2, $this->graphRequests());
    }

    public function test_without_a_scheduled_date_the_technician_is_told_it_is_unscheduled_and_the_customer_hears_nothing(): void
    {
        $wo = $this->workOrder(['scheduled_date' => null]);

        $this->assign($wo, $this->technician());

        $this->assertSame([
            '6281355550001 spk_penugasan_teknisi' => ['SPK-2026-0001', 'Pasang ONT baru', 'belum dijadwalkan', route('admin.spk.show', $wo)],
        ], $this->sent());
    }

    public function test_without_a_customer_only_the_technician_is_messaged(): void
    {
        $wo = $this->workOrder(['customer_id' => null]);

        $this->assign($wo, $this->technician());

        $this->assertSame(['6281355550001 spk_penugasan_teknisi'], array_keys($this->sent()));
    }

    public function test_a_customer_without_consent_is_skipped_while_the_technician_is_still_messaged(): void
    {
        $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save();

        $this->assign($this->workOrder(), $this->technician());

        $this->assertSame(['6281355550001 spk_penugasan_teknisi'], array_keys($this->sent()));
        $skipped = $this->whatsAppRows()->firstWhere('event', 'spk_scheduled_customer');
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('no_consent', $skipped->skip_reason);
    }

    public function test_a_technician_without_a_valid_phone_is_skipped_and_the_assignment_still_succeeds(): void
    {
        $wo = $this->workOrder();
        $technician = $this->technician('Andi Wijaya', '021-5551234');

        $assigned = $this->assign($wo, $technician);

        $this->assertSame($technician->id, $assigned->assigned_to);
        $this->assertSame('assigned', $assigned->status);
        $row = $this->whatsAppRows()->firstWhere('event', 'spk_scheduled');
        $this->assertSame('skipped', $row->status);
        $this->assertSame('invalid_phone', $row->skip_reason);
        $this->assertSame(['6281234567890 spk_jadwal_pelanggan'], array_keys($this->sent()));
    }

    public function test_a_technician_who_lost_the_active_profile_before_the_job_ran_is_skipped(): void
    {
        $wo = $this->workOrder();
        $technician = $this->technician();
        Queue::fake();
        $this->assign($wo, $technician);
        EmployeeProfile::query()->where('user_id', $technician->id)->update(['status' => 'inactive']);

        Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->channels === [WhatsAppChannel::class] && $job->notification->audience === 'technician')
            ->sole()
            ->handle(app(ChannelManager::class));

        Http::assertNothingSent();
        $this->assertSame('no_consent', $this->whatsAppRows()->sole()->skip_reason);
    }

    public function test_a_technician_who_was_replaced_before_the_job_ran_is_not_told(): void
    {
        $wo = $this->workOrder();
        $first = $this->technician('Andi Wijaya', '0813-5555-0001');
        $second = $this->technician('Rina Putri', '0813-5555-0002');
        Queue::fake();
        $this->assign($wo, $first);
        $this->assign($wo, $second, $first->id);

        Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->channels === [WhatsAppChannel::class] && $job->notification->audience === 'technician' && $job->notifiables->first()->is($first))
            ->sole()
            ->handle(app(ChannelManager::class));

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_an_assignment_that_aborts_sends_nothing(): void
    {
        $wo = $this->workOrder();
        $technician = $this->technician();

        try {
            SpkService::assign($wo->fresh(), $technician->id, $this->admin->id, null, now()->subMinute()->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
            $this->fail('the stale state must be refused');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        Http::assertNothingSent();
        $this->assertNull($wo->fresh()->assigned_to);
    }

    public function test_a_rollback_after_the_assignment_sends_nothing(): void
    {
        $wo = $this->workOrder();
        $technician = $this->technician();

        try {
            DB::transaction(function () use ($wo, $technician): void {
                $this->assign($wo, $technician);

                throw new RuntimeException('abort after the assignment');
            });
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
        $this->assertNull($wo->fresh()->assigned_to);
    }

    public function test_both_messages_go_out_at_once_on_the_whatsapp_queue_with_ids_only(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-14 15:00:00'); // 22:00 in Jakarta: quiet hours do not hold event-driven messages
        $wo = $this->workOrder();
        $technician = $this->technician();

        $this->assign($wo, $technician);

        $queued = Queue::pushed(SendQueuedNotifications::class);
        $jobs = $queued->filter(fn (SendQueuedNotifications $job): bool => $job->channels === [WhatsAppChannel::class]);
        $this->assertCount(2, $jobs);
        $this->assertCount(1, $queued->filter(fn (SendQueuedNotifications $job): bool => $job->channels === ['database']), 'the technician, a user, also gets the in-app notification; the customer has no portal user');
        foreach ($jobs as $job) {
            $this->assertSame('whatsapp', $job->queue);
            $this->assertNull($job->delay);
            foreach (['Pasang ONT baru', 'SPK-2026-0001', 'PT Contoh Net', '0813', '0812'] as $personal) {
                $this->assertStringNotContainsString($personal, serialize($job));
            }
            $this->assertSame($wo->id, $job->notification->workOrderId);
            $this->assertSame($this->company->id, $job->notification->companyId);
        }
        $this->assertEqualsCanonicalizing(['technician', 'customer'], $jobs->map(fn (SendQueuedNotifications $job): string => $job->notification->audience)->all());
    }

    public function test_the_notified_models_are_the_technician_and_the_customer(): void
    {
        Notification::fake();
        $wo = $this->workOrder();
        $technician = $this->technician();

        $this->assign($wo, $technician);

        Notification::assertSentTo($technician, WorkOrderScheduledNotification::class, fn (WorkOrderScheduledNotification $n): bool => $n->audience === 'technician' && $n->workOrderId === $wo->id);
        Notification::assertSentTo($this->customer, WorkOrderScheduledNotification::class, fn (WorkOrderScheduledNotification $n): bool => $n->audience === 'customer');
    }

    public function test_an_unknown_audience_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkOrderScheduledNotification($this->company->id, 1, 'supplier');
    }
}
