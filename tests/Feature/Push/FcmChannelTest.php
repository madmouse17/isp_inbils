<?php

namespace Tests\Feature\Push;

use App\Jobs\SendPushNotification;
use App\Models\Core\Customer;
use App\Models\Core\DeviceToken;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\FcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Push\Fixtures\ProbePushNotification;
use Tests\TestCase;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The push twin of the bell, with the Firebase client faked: what the message says, who it reaches, what happens to a
 * token FCM rejects, and that nothing goes out for a change that never committed.
 */
class FcmChannelTest extends TestCase
{
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const TICKET = ['type' => 'ticket_assigned', 'ticket_id' => 17, 'ticket_code' => 'TKT-17'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
    }

    private function install(User $user, string $app, string $token): DeviceToken
    {
        return DeviceToken::factory()->create(['user_id' => $user->id, 'app' => $app, 'token' => $token]);
    }

    public function test_the_push_says_what_the_bell_says_and_carries_the_id_of_the_bell_row(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'tech-token-1');

        $technician->notify(new ProbePushNotification(self::TICKET + ['company_id' => $technician->company_id]));

        $bell = $technician->notifications()->sole();
        $push = $this->onlyPush();

        $this->assertSame(['tech-token-1'], $this->pushedTokens());
        $this->assertSame(['title' => 'Tiket ditugaskan', 'body' => 'Tiket TKT-17 sudah ditugaskan kepada Anda.'], $push['notification']);
        $this->assertSame([
            'type' => 'ticket_assigned',
            'subject_type' => 'ticket',
            'subject_id' => '17',
            'notification_id' => $bell->id,
            'title' => 'Tiket ditugaskan',
            'body' => 'Tiket TKT-17 sudah ditugaskan kepada Anda.',
        ], $push['data']);
        $this->assertSame(
            ['priority' => 'high', 'ttl' => '86400s', 'notification' => ['channel_id' => 'inbils', 'tag' => $bell->id]],
            $push['android'],
        );
    }

    public function test_a_notification_without_a_subject_pushes_empty_subject_strings(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'tech-token-1');

        $technician->notify(new ProbePushNotification(['type' => 'billing_schedule_failed']));

        $data = $this->onlyPush()['data'];
        $this->assertSame(['type' => 'billing_schedule_failed', 'subject_type' => '', 'subject_id' => ''], array_intersect_key($data, array_flip(['type', 'subject_type', 'subject_id'])));
        foreach ($data as $key => $value) {
            $this->assertIsString($value, "data.{$key} must be a string for FCM");
        }
    }

    public function test_a_customer_notification_reaches_customer_installs_and_a_user_notification_technician_installs(): void
    {
        [$user, $customer] = $this->customerLogin();
        $user->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'status' => 'active']);
        $this->install($user, 'customer', 'customer-install');
        $this->install($user, 'technician', 'technician-install');

        $customer->notify(new ProbePushNotification(['type' => 'invoice_issued', 'invoice_id' => 5]));
        $this->assertSame(['customer-install'], $this->pushedTokens());
        $this->assertSame('Tagihan baru', $this->multicasts[0]['message']['notification']['title']);

        $this->multicasts = [];
        $user->notify(new ProbePushNotification(self::TICKET));
        $this->assertSame(['technician-install'], $this->pushedTokens());
    }

    public function test_a_customer_without_a_login_and_a_user_without_an_install_get_no_push_channel(): void
    {
        [$user, $customer] = $this->customerLogin();
        $other = $this->technicianLogin($user->company);
        $unlinked = Customer::factory()->create(['company_id' => $user->company_id, 'user_id' => null]);
        $this->install($user, 'customer', 'customer-install');
        Queue::fake();

        $this->assertSame([FcmChannel::class], FcmChannel::for($customer));
        $this->assertSame([], FcmChannel::for($unlinked));
        $this->assertSame([], FcmChannel::for($other));
        $this->assertSame([], FcmChannel::for($user), 'the customer install is not a technician install');

        $other->notify(new ProbePushNotification(self::TICKET));
        $unlinked->notify(new ProbePushNotification(['type' => 'invoice_issued', 'invoice_id' => 5]));
        Queue::assertNotPushed(SendPushNotification::class);
        $this->assertSame([], $this->multicasts);
    }

    public function test_push_is_off_while_the_credentials_path_is_empty(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'tech-token-1');
        config(['firebase.projects.app.credentials' => null]);

        $technician->notify(new ProbePushNotification(self::TICKET));

        $this->assertFalse(FcmSender::isConfigured());
        $this->assertSame([], FcmChannel::for($technician));
        $this->assertSame([], $this->multicasts);
        $this->assertSame(1, $technician->notifications()->count(), 'the bell is not affected');
    }

    public function test_an_install_whose_sign_in_expired_or_was_revoked_is_not_reached(): void
    {
        $technician = $this->technicianLogin();
        $expired = $this->install($technician, 'technician', 'expired-install');
        $live = $this->install($technician, 'technician', 'live-install');
        $revoked = $this->install($technician, 'technician', 'revoked-install');
        $expired->personalAccessToken->forceFill(['expires_at' => now()->subMinute()])->save();
        $revoked->personalAccessToken->delete();

        $technician->notify(new ProbePushNotification(self::TICKET));

        $this->assertSame(['live-install'], $this->pushedTokens());
        $this->assertNotNull($live->fresh());
        $this->assertNull($revoked->fresh(), 'revoking the sign-in deleted the registration with it');
    }

    public function test_every_install_of_the_user_gets_the_push_in_one_call(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'phone-install');
        $this->install($technician, 'technician', 'tablet-install');

        $technician->notify(new ProbePushNotification(self::TICKET));

        $this->assertCount(1, $this->multicasts);
        $this->assertEqualsCanonicalizing(['phone-install', 'tablet-install'], $this->multicasts[0]['tokens']);
    }

    public function test_tokens_fcm_calls_unregistered_or_invalid_are_deleted_and_the_rest_stay(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'good-install');
        $this->install($technician, 'technician', 'uninstalled-install');
        $this->install($technician, 'technician', 'garbage-install');
        $this->fcmUnregistered('uninstalled-install');
        $this->fcmInvalid('garbage-install');

        $technician->notify(new ProbePushNotification(self::TICKET));

        $this->assertSame(['good-install'], DeviceToken::query()->pluck('token')->all());
    }

    public function test_a_transient_failure_keeps_the_token_logs_no_secret_and_is_retried_only_when_nothing_was_delivered(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'good-install-0123456789');
        $this->install($technician, 'technician', 'busy-install-0123456789');
        $this->fcmUnavailable('busy-install-0123456789');
        Queue::fake();
        $technician->notify(new ProbePushNotification(self::TICKET));
        $job = Queue::pushed(SendPushNotification::class)->sole();
        Log::spy();

        // One install was reached: no exception, so the queue does not send the push to it twice.
        app()->call([$job, 'handle']);

        $this->assertSame(2, DeviceToken::query()->count(), 'a 503 says nothing about the token');
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('push.send_failed', $message);
            $this->assertSame('456789', $context['token'], 'only the last characters of a token are logged');
            $this->assertSame('ServerUnavailable', $context['error']);
            $this->assertStringNotContainsString('install', json_encode($context, JSON_THROW_ON_ERROR), 'no token and no message text in the log');

            return true;
        });

        // Nothing was reached: the job fails so the queue retries it, with no token and no message in the exception.
        DeviceToken::query()->where('token', 'like', 'good%')->delete();
        try {
            app()->call([$job, 'handle']);
            $this->fail('the job should have failed so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('install', $e->getMessage());
            $this->assertStringNotContainsString('Tiket', $e->getMessage());
        }
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff());
    }

    public function test_a_rolled_back_change_pushes_nothing_and_a_committed_one_pushes_once(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'tech-token-1');

        try {
            DB::transaction(function () use ($technician): void {
                $technician->notify(new ProbePushNotification(self::TICKET));

                throw new RuntimeException('the action failed after it notified');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->multicasts, 'the push waited for a commit that never came');

        DB::transaction(function () use ($technician): void {
            $technician->notify(new ProbePushNotification(self::TICKET));

            $this->assertSame([], $this->multicasts, 'still inside the transaction');
        });

        $this->assertCount(1, $this->multicasts);
    }

    public function test_the_queued_job_holds_the_message_and_never_a_registration_token(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'secret-registration-token');
        Queue::fake();

        $technician->notify(new ProbePushNotification(self::TICKET));

        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) use ($technician): bool {
            $this->assertSame($technician->id, $job->userId);
            $this->assertSame('technician', $job->app);
            $this->assertStringNotContainsString('secret-registration-token', serialize($job));

            return true;
        });
    }

    public function test_the_job_finds_the_installs_when_it_runs_and_does_nothing_for_a_user_who_has_none_left(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'tech-token-1');
        Queue::fake();
        $technician->notify(new ProbePushNotification(self::TICKET));
        $job = Queue::pushed(SendPushNotification::class)->first();

        DeviceToken::query()->delete();
        app()->call([$job, 'handle']);
        $this->assertSame([], $this->multicasts);

        $this->install($technician, 'technician', 'registered-meanwhile');
        app()->call([$job, 'handle']);
        $this->assertSame(['registered-meanwhile'], $this->pushedTokens());
    }

    public function test_a_failing_push_never_takes_the_notification_down_with_it(): void
    {
        $technician = $this->technicianLogin();
        $this->install($technician, 'technician', 'tech-token-1');
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('the queue is down'));

        $technician->notify(new ProbePushNotification(self::TICKET));

        $this->assertSame(1, $technician->notifications()->count(), 'the bell row is there and the caller did not see the failure');
    }
}
