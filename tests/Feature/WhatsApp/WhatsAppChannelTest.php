<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppTransientError;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\WhatsApp\Fixtures\ProbeNotification;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppChannelTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->company = $this->whatsAppEnabledFor(Company::factory()->create(['timezone' => 'Asia/Jakarta']));
        $this->customer = $this->consentedCustomer($this->company);
    }

    private function deliver(object $notifiable, ?ProbeNotification $notification = null): void
    {
        Notification::send($notifiable, $notification ?? new ProbeNotification($this->company->id));
    }

    private function onlyRow(): WhatsAppMessage
    {
        $rows = $this->whatsAppRows();
        $this->assertCount(1, $rows);

        return $rows->first();
    }

    public function test_without_credentials_the_message_is_skipped_as_disabled_and_nothing_is_called(): void
    {
        $this->disableWhatsApp();

        $this->deliver($this->customer);

        $row = $this->onlyRow();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('disabled', $row->skip_reason);
        Http::assertNothingSent();
    }

    public function test_a_consented_customer_gets_exactly_one_template_with_the_parameters_in_order(): void
    {
        $this->whatsAppReplies(200, ['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.ABC123']]]);

        $this->deliver($this->customer);

        $this->assertCount(1, $this->graphRequests());
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->header('Authorization') === ['Bearer '.self::WA_TOKEN]
                && $data['to'] === '6281234567890'
                && $data['template']['name'] === 'pembayaran_diterima'
                && $data['template']['language'] === ['code' => 'id']
                && array_column($data['template']['components'][0]['parameters'], 'text') === ['PT Contoh Net', 'INV-2026-00012', 'Rp 250.000', 'Rp 0', '14 Okt 2026'];
        });

        $row = $this->onlyRow();
        $this->assertSame('sent', $row->status);
        $this->assertSame('wamid.ABC123', $row->provider_message_id);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertSame('customer', $row->recipient_type);
        $this->assertSame($this->customer->id, $row->recipient_id);
        $this->assertSame('payment_received', $row->event);
        $this->assertSame('invoice', $row->subject_type);
        $this->assertSame(12, $row->subject_id);
        $this->assertSame('pembayaran_diterima', $row->template);
        $this->assertSame('id', $row->language);
        $this->assertSame('probe:1', $row->dedupe_key);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->skip_reason);
        $this->assertNull($row->error_code);
    }

    public function test_the_row_keeps_a_keyed_hash_and_the_last_four_digits_never_the_number_or_the_parameters(): void
    {
        $this->deliver($this->customer);

        $row = $this->onlyRow();
        $this->assertSame('7890', $row->recipient_last4);
        $this->assertSame(hash_hmac('sha256', '6281234567890', config('app.key')), $row->recipient_hash);

        $stored = json_encode($row->getAttributes());
        foreach (['6281234567890', '081234567890', '0812-3456-7890', 'PT Contoh Net', 'INV-2026-00012', 'Rp 250.000', self::WA_TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function skipReasons(): array
    {
        return [
            'company switch off' => ['disabled'],
            'event switched off' => ['event_disabled'],
            'no consent recorded' => ['no_consent'],
            'consent withdrawn' => ['opted_out'],
            'landline' => ['invalid_phone'],
            'over the monthly cap' => ['budget_exceeded'],
        ];
    }

    #[DataProvider('skipReasons')]
    public function test_every_skip_reason_leaves_one_skipped_row_and_makes_no_call(string $reason): void
    {
        match ($reason) {
            'disabled' => $this->whatsAppEnabledFor($this->company, ['whatsapp_enabled' => false]),
            'event_disabled' => $this->whatsAppEnabledFor($this->company, ['whatsapp_events' => ['invoice_issued']]),
            'no_consent' => $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save(),
            'opted_out' => $this->customer->forceFill(['whatsapp_opt_out_at' => now()])->save(),
            'invalid_phone' => $this->customer->forceFill(['phone' => '021-5551234'])->save(),
            'budget_exceeded' => $this->exhaustTheCap(),
        };

        $this->deliver($this->customer);

        $rows = $this->whatsAppRows()->where('dedupe_key', 'probe:1');
        $this->assertCount(1, $rows);
        $this->assertSame('skipped', $rows->first()->status);
        $this->assertSame($reason, $rows->first()->skip_reason);
        $this->assertNull($rows->first()->provider_message_id);
        Http::assertNothingSent();
    }

    private function exhaustTheCap(): void
    {
        $this->whatsAppEnabledFor($this->company, ['whatsapp_monthly_cap' => 2]);
        WhatsAppMessage::factory()->count(2)->create(['company_id' => $this->company->id]);
    }

    public function test_replaying_a_sent_notification_sends_nothing(): void
    {
        $this->deliver($this->customer);
        $this->deliver($this->customer);
        $this->deliver($this->customer, new ProbeNotification($this->company->id));

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame('sent', $this->onlyRow()->status);
    }

    public function test_a_replay_after_consent_was_withdrawn_does_not_touch_the_sent_row(): void
    {
        $this->deliver($this->customer);
        $this->customer->forceFill(['whatsapp_opt_out_at' => now()])->save();

        $this->deliver($this->customer);

        $row = $this->onlyRow();
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->skip_reason);
    }

    public function test_a_skipped_row_is_overwritten_when_a_later_attempt_with_the_same_key_succeeds(): void
    {
        $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save();
        $this->deliver($this->customer);
        $this->assertSame('no_consent', $this->onlyRow()->skip_reason);

        $this->customer->forceFill(['whatsapp_opt_in_at' => now()])->save();
        $this->deliver($this->customer);

        $row = $this->onlyRow();
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->skip_reason);
        $this->assertCount(1, $this->graphRequests());
    }

    public function test_a_transient_failure_keeps_the_message_queued_and_the_retry_sends_it_once(): void
    {
        $this->whatsAppReplies(500, ['error' => ['code' => 2, 'message' => '(#2) Service temporarily unavailable']]);

        try {
            $this->deliver($this->customer);
            $this->fail('the queue must see the failure to retry');
        } catch (WhatsAppTransientError $e) {
            $this->assertSame('2', $e->errorCode);
        }

        $row = $this->onlyRow();
        $this->assertSame('queued', $row->status);
        $this->assertSame('2', $row->error_code);

        $this->whatsAppReply = null;
        $this->deliver($this->customer);

        $row = $this->onlyRow();
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->error_code);
        $this->assertCount(2, $this->graphRequests(), 'one failed attempt and one that Meta accepted');
    }

    public function test_a_timeout_is_retried_the_same_way(): void
    {
        $this->whatsAppReply = fn () => throw new ConnectionException('timed out');

        try {
            $this->deliver($this->customer);
            $this->fail('the queue must see the failure to retry');
        } catch (WhatsAppTransientError $e) {
            $this->assertSame('connection', $e->errorCode);
        }

        $this->assertSame('queued', $this->onlyRow()->status);
        $this->whatsAppReply = null;
        $this->deliver($this->customer);
        $this->assertSame('sent', $this->onlyRow()->status);
    }

    public function test_a_permanent_rejection_marks_the_row_failed_with_the_code_and_is_never_retried(): void
    {
        Log::spy();
        $this->whatsAppReplies(400, ['error' => ['code' => 131026, 'message' => '(#131026) Message undeliverable', 'error_data' => ['details' => 'recipient 6281234567890']]]);

        $this->deliver($this->customer);
        $this->deliver($this->customer);

        $row = $this->onlyRow();
        $this->assertSame('failed', $row->status);
        $this->assertSame('131026', $row->error_code);
        $this->assertNotNull($row->failed_at);
        $this->assertCount(1, $this->graphRequests(), 'a failed key is not sent again');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'whatsapp.send_rejected'
            && ($context['error_code'] ?? null) === '131026'
            && ! str_contains(json_encode($context), '628')
            && ! str_contains(json_encode($context), self::WA_TOKEN));
    }

    public function test_a_queued_row_of_a_crashed_attempt_is_retaken_after_a_minute_but_not_before(): void
    {
        $this->travelTo('2026-10-14 03:00:00');
        WhatsAppMessage::factory()->create([
            'company_id' => $this->company->id,
            'recipient_id' => $this->customer->id,
            'dedupe_key' => 'probe:1',
            'status' => 'queued',
            'provider_message_id' => null,
            'sent_at' => null,
        ]);

        $this->travelTo('2026-10-14 03:00:30');
        $this->deliver($this->customer);
        Http::assertNothingSent();

        $this->travelTo('2026-10-14 03:01:30');
        $this->deliver($this->customer);

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame('sent', $this->onlyRow()->status);
    }

    public function test_a_notification_with_nothing_to_send_writes_no_row_and_makes_no_call(): void
    {
        $this->deliver($this->customer, new ProbeNotification($this->company->id, nothingToSend: true));

        $this->assertCount(0, $this->whatsAppRows());
        Http::assertNothingSent();
    }

    public function test_the_monthly_cap_counts_the_messages_sent_in_the_company_month(): void
    {
        $this->whatsAppEnabledFor($this->company, ['whatsapp_monthly_cap' => 2]);
        // 2026-10-14 is a Wednesday; Jakarta is UTC+7, so the month started on 2026-09-30 17:00 UTC.
        $this->travelTo('2026-10-14 03:00:00');
        $make = fn (array $attributes) => WhatsAppMessage::factory()->create($attributes + ['company_id' => $this->company->id]);
        $make(['sent_at' => '2026-09-30 16:59:59']); // the last second of September
        $make(['sent_at' => '2026-09-15 10:00:00']);
        $make(['status' => 'delivered', 'sent_at' => '2026-09-30 17:00:00']); // the first second of October: counts
        WhatsAppMessage::factory()->skipped('no_consent')->create(['company_id' => $this->company->id]);
        $make(['status' => 'failed', 'sent_at' => null, 'provider_message_id' => null]);
        $make(['status' => 'queued', 'sent_at' => null, 'provider_message_id' => null, 'updated_at' => '2026-10-14 00:30:00']); // its retries are long used up

        $this->deliver($this->customer);
        $this->assertSame('sent', $this->onlyRowWithKey('probe:1')->status, 'one message sent this month, cap is 2');

        $this->deliver($this->customer, new ProbeNotification($this->company->id, 'probe:2'));
        $this->assertSame('budget_exceeded', $this->onlyRowWithKey('probe:2')->skip_reason);
    }

    public function test_a_message_created_in_one_month_and_sent_in_the_next_counts_for_the_month_it_was_sent_in(): void
    {
        $this->whatsAppEnabledFor($this->company, ['whatsapp_monthly_cap' => 1]);
        $this->travelTo('2026-09-30 17:30:00'); // 00:30 on 1 October in Jakarta
        WhatsAppMessage::factory()->create(['company_id' => $this->company->id, 'created_at' => '2026-09-30 16:55:00', 'sent_at' => '2026-09-30 17:05:00']);

        $this->deliver($this->customer);

        $this->assertSame('budget_exceeded', $this->onlyRowWithKey('probe:1')->skip_reason);
        Http::assertNothingSent();
    }

    public function test_a_message_in_flight_holds_its_slot_until_it_is_sent_or_given_up(): void
    {
        $this->whatsAppEnabledFor($this->company, ['whatsapp_monthly_cap' => 1]);
        $this->travelTo('2026-10-14 03:00:00');
        WhatsAppMessage::factory()->create(['company_id' => $this->company->id, 'recipient_id' => 999, 'dedupe_key' => 'other:1', 'status' => 'queued', 'sent_at' => null, 'provider_message_id' => null]); // another worker is sending

        $this->deliver($this->customer);
        $this->assertSame('budget_exceeded', $this->onlyRowWithKey('probe:1')->skip_reason);
        Http::assertNothingSent();

        $this->travelTo('2026-10-14 05:01:00'); // two hours on: the other message used up its retries and no longer holds the slot
        $this->deliver($this->customer);

        $this->assertSame('sent', $this->onlyRowWithKey('probe:1')->status);
        $this->assertCount(1, $this->graphRequests());
    }

    public function test_a_retry_is_not_blocked_by_the_slot_its_own_failed_attempt_reserved(): void
    {
        $this->whatsAppEnabledFor($this->company, ['whatsapp_monthly_cap' => 1]);
        $this->whatsAppReplies(500, ['error' => ['code' => 2, 'message' => '(#2) Service temporarily unavailable']]);

        try {
            $this->deliver($this->customer);
            $this->fail('the queue must see the failure to retry');
        } catch (WhatsAppTransientError) {
        }
        $this->assertSame('queued', $this->onlyRow()->status);

        $this->whatsAppReply = null;
        $this->deliver($this->customer);

        $this->assertSame('sent', $this->onlyRow()->status);
    }

    public function test_two_workers_with_one_slot_left_send_one_message(): void
    {
        $this->whatsAppEnabledFor($this->company, ['whatsapp_monthly_cap' => 1]);
        $secondWorker = new ProbeNotification($this->company->id, 'probe:2');
        $this->whatsAppReply = function () use ($secondWorker) {
            // The second worker takes its turn while the first one is still talking to Meta.
            $this->deliver($this->customer, $secondWorker);

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.FIRST']]]);
        };

        $this->deliver($this->customer);

        $this->assertCount(1, $this->graphRequests(), 'the last slot was sold once');
        $this->assertSame('sent', $this->onlyRowWithKey('probe:1')->status);
        $this->assertSame('budget_exceeded', $this->onlyRowWithKey('probe:2')->skip_reason);
    }

    public function test_the_decision_and_the_reservation_are_taken_under_the_company_row_lock(): void
    {
        $statements = [];
        $levelBefore = DB::transactionLevel();
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });

        $this->deliver($this->customer);

        $locks = array_keys(array_filter($statements, fn (array $s): bool => str_contains($s['sql'], 'from `companies`') && str_ends_with(rtrim($s['sql']), 'for update')));
        $this->assertCount(1, $locks, 'the company row is locked once per message');
        $this->assertGreaterThan($levelBefore, $statements[$locks[0]]['level'], 'the lock is taken inside the delivery transaction');
        $firstTouchOfTheLog = array_key_first(array_filter($statements, fn (array $s): bool => str_contains($s['sql'], '`whatsapp_messages`')));
        $this->assertLessThan($firstTouchOfTheLog, $locks[0], 'the lock comes before the cap is counted and the key is claimed');
    }

    private function onlyRowWithKey(string $key): WhatsAppMessage
    {
        $rows = $this->whatsAppRows()->where('dedupe_key', $key);
        $this->assertCount(1, $rows);

        return $rows->first();
    }

    public function test_a_technician_gets_the_message_without_consent_when_active_with_a_valid_phone(): void
    {
        $technician = $this->technician($this->company);

        $this->deliver($technician, new ProbeNotification($this->company->id, 'probe:t', 'spk_scheduled'));

        $row = $this->onlyRowWithKey('probe:t');
        $this->assertSame('sent', $row->status);
        $this->assertSame('user', $row->recipient_type);
        $this->assertSame($technician->id, $row->recipient_id);
        Http::assertSent(fn (Request $request): bool => $request->data()['to'] === '6281355550001');
    }

    public function test_a_technician_without_an_active_profile_or_a_valid_phone_is_skipped(): void
    {
        $inactiveProfile = $this->technician($this->company, profileStatus: 'inactive');
        $landline = $this->technician($this->company, '021-5551234');
        $inactiveUser = $this->technician($this->company);
        $inactiveUser->forceFill(['is_active' => false])->save();

        $this->deliver($inactiveProfile, new ProbeNotification($this->company->id, 'probe:a'));
        $this->deliver($landline, new ProbeNotification($this->company->id, 'probe:b'));
        $this->deliver($inactiveUser, new ProbeNotification($this->company->id, 'probe:c'));

        $this->assertSame('no_consent', $this->onlyRowWithKey('probe:a')->skip_reason);
        $this->assertSame('invalid_phone', $this->onlyRowWithKey('probe:b')->skip_reason);
        $this->assertSame('no_consent', $this->onlyRowWithKey('probe:c')->skip_reason);
        Http::assertNothingSent();
    }

    public function test_a_customers_portal_user_stands_for_the_customer_with_the_customers_consent_and_phone(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->customer->forceFill(['user_id' => $user->id])->save();

        $this->deliver($user);

        $row = $this->onlyRow();
        $this->assertSame('customer', $row->recipient_type);
        $this->assertSame($this->customer->id, $row->recipient_id);
        $this->assertSame('sent', $row->status);

        $this->customer->forceFill(['whatsapp_opt_out_at' => now()])->save();
        $this->deliver($user, new ProbeNotification($this->company->id, 'probe:2'));
        $this->assertSame('opted_out', $this->onlyRowWithKey('probe:2')->skip_reason);
    }

    public function test_a_company_only_ever_reads_its_own_settings_and_writes_its_own_rows(): void
    {
        $other = Company::factory()->create(['timezone' => 'Asia/Jakarta', 'settings' => ['whatsapp_enabled' => false]]);
        $otherCustomer = $this->consentedCustomer($other, '0812-3456-7890');

        // The same phone in both companies; only company A has the feature on.
        $this->deliver($this->customer);
        $this->deliver($otherCustomer, new ProbeNotification($other->id, 'probe:b'));

        $this->assertSame('sent', $this->whatsAppRows($this->company->id)->sole()->status);
        $rowB = $this->whatsAppRows($other->id)->sole();
        $this->assertSame('disabled', $rowB->skip_reason);
        $this->assertSame($other->id, $rowB->company_id);
        $this->assertCount(1, $this->graphRequests());
    }

    public function test_a_customer_of_another_company_is_nobody_for_this_notification(): void
    {
        $other = Company::factory()->create(['settings' => ['whatsapp_enabled' => true]]);
        $foreign = $this->consentedCustomer($other);

        $this->deliver($foreign, new ProbeNotification($this->company->id));

        $this->assertCount(0, $this->whatsAppRows($other->id), 'nothing is written for the other company');
        $this->assertSame('no_consent', $this->whatsAppRows($this->company->id)->sole()->skip_reason);
        Http::assertNothingSent();
    }

    public function test_the_queued_job_carries_ids_only_and_is_routed_rate_limited_and_after_commit(): void
    {
        Queue::fake();

        $this->deliver($this->customer, new ProbeNotification($this->company->id));

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            $payload = serialize($job);

            foreach (array_filter(['0812', '81234567890', $this->customer->name, (string) $this->customer->email, 'PT Contoh Net']) as $personal) {
                $this->assertStringNotContainsString($personal, $payload);
            }

            $this->assertSame('whatsapp', $job->queue);
            $this->assertTrue($job->afterCommit);
            $this->assertSame(5, $job->tries);
            $this->assertSame([60, 300, 900, 3600], $job->backoff());
            $this->assertSame([WhatsAppChannel::class], $job->channels);
            $this->assertCount(1, $job->middleware);
            $this->assertInstanceOf(RateLimited::class, $job->middleware[0]);

            return true;
        });
    }

    public function test_batch_events_wait_for_the_end_of_quiet_hours_and_the_others_do_not(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-14 15:00:00'); // 22:00 in Jakarta

        $this->deliver($this->customer, new ProbeNotification($this->company->id, 'probe:batch', 'invoice_issued'));
        $this->deliver($this->customer, new ProbeNotification($this->company->id, 'probe:now', 'payment_received'));

        $delays = [];
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use (&$delays): bool {
            $delays[$job->notification->dedupeKey] = $job->delay;

            return true;
        });
        $this->assertEquals(CarbonImmutable::parse('2026-10-15 07:00:00', 'Asia/Jakarta'), $delays['probe:batch']);
        $this->assertNull($delays['probe:now']);
    }

    public function test_quiet_delay_is_the_end_of_the_window_in_company_time(): void
    {
        $id = $this->company->id;

        $this->travelTo('2026-10-14 15:00:00'); // 22:00 WIB
        $this->assertEquals(CarbonImmutable::parse('2026-10-15 07:00:00', 'Asia/Jakarta'), WhatsAppChannel::quietDelay($id));

        $this->travelTo('2026-10-14 03:00:00'); // 10:00 WIB
        $this->assertNull(WhatsAppChannel::quietDelay($id));

        $this->travelTo('2026-10-13 22:00:00'); // 05:00 WIB next day: still inside last night's window
        $this->assertEquals(CarbonImmutable::parse('2026-10-14 07:00:00', 'Asia/Jakarta'), WhatsAppChannel::quietDelay($id));

        $this->travelTo('2026-10-14 14:00:00'); // 21:00 WIB sharp: the window has begun
        $this->assertEquals(CarbonImmutable::parse('2026-10-15 07:00:00', 'Asia/Jakarta'), WhatsAppChannel::quietDelay($id));

        $this->travelTo('2026-10-14 00:00:00'); // 07:00 WIB sharp: the window is over
        $this->assertNull(WhatsAppChannel::quietDelay($id));
    }

    public function test_quiet_window_is_configurable_per_company_and_falls_back_on_garbage(): void
    {
        $id = $this->company->id;
        $this->travelTo('2026-10-14 06:00:00'); // 13:00 WIB

        $this->whatsAppEnabledFor($this->company, ['whatsapp_quiet_start' => '12:00', 'whatsapp_quiet_end' => '14:00']);
        $this->assertEquals(CarbonImmutable::parse('2026-10-14 14:00:00', 'Asia/Jakarta'), WhatsAppChannel::quietDelay($id), 'a window inside one day');

        $this->whatsAppEnabledFor($this->company, ['whatsapp_quiet_start' => '12:00', 'whatsapp_quiet_end' => '12:00']);
        $this->assertNull(WhatsAppChannel::quietDelay($id), 'equal times mean no window');

        $this->whatsAppEnabledFor($this->company, ['whatsapp_quiet_start' => '25:99', 'whatsapp_quiet_end' => 'soon']);
        $this->assertNull(WhatsAppChannel::quietDelay($id), '13:00 is outside the 21:00-07:00 default');
    }

    public function test_settings_default_to_off_with_every_event_and_the_configured_cap(): void
    {
        $settings = WhatsAppSettings::for(Company::factory()->create()->id);

        $this->assertFalse($settings->enabled());
        $this->assertTrue($settings->eventEnabled('invoice_issued'));
        $this->assertTrue($settings->eventEnabled('access_link_notice'));
        $this->assertFalse($settings->eventEnabled('no_such_event'));
        $this->assertSame(1000, $settings->monthlyCap());
        $this->assertSame(['whatsapp_enabled', 'whatsapp_events', 'whatsapp_quiet_start', 'whatsapp_quiet_end', 'whatsapp_monthly_cap'], array_keys(WhatsAppSettings::defaults()));
    }

    public function test_the_send_rate_limit_is_per_company(): void
    {
        $limiter = RateLimiter::limiter('whatsapp-send');
        $jobFor = fn (int $companyId): SendQueuedNotifications => new SendQueuedNotifications($this->customer, new ProbeNotification($companyId), ['x']);

        $a = $limiter($jobFor(1));
        $b = $limiter($jobFor(2));

        $this->assertSame(20, $a->maxAttempts);
        $this->assertSame(1, $a->decaySeconds);
        $this->assertSame('company:1', $a->key);
        $this->assertSame('company:2', $b->key);
    }

    public function test_messages_older_than_twelve_months_are_pruned_in_every_company(): void
    {
        $other = Company::factory()->create();
        $old = WhatsAppMessage::factory()->create(['company_id' => $this->company->id, 'created_at' => now()->subMonths(12)->subDay()]);
        $oldOther = WhatsAppMessage::factory()->create(['company_id' => $other->id, 'created_at' => now()->subMonths(13)]);
        $recent = WhatsAppMessage::factory()->create(['company_id' => $this->company->id, 'created_at' => now()->subMonths(11)]);

        $this->artisan('model:prune', ['--model' => [WhatsAppMessage::class]])->assertSuccessful();

        $this->assertSame([$recent->id], $this->whatsAppRows()->pluck('id')->all());
        $this->assertNotContains($old->id, $this->whatsAppRows()->pluck('id')->all());
        $this->assertNotContains($oldOther->id, $this->whatsAppRows()->pluck('id')->all());
    }
}
