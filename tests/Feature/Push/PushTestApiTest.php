<?php

namespace Tests\Feature\Push;

use App\Models\Core\Customer;
use App\Models\Core\DeviceToken;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * POST api/v1/me/push-test: the install asks for one test push on the FCM token it holds. The bell row exists before the
 * 202, FCM is queued, and only the install that asked is pushed: the user's other phones never buzz.
 */
class PushTestApiTest extends TestCase
{
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const THIS_PHONE = 'this-phone-fcm-token-0001';

    private const OTHER_PHONE = 'other-phone-fcm-token-0002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
    }

    /** A registration of $fcm bound to the sign-in $bearer, the way POST me/device-token binds it. */
    private function register(User $user, string $app, string $bearer, string $fcm, string $name = 'Pixel 7'): DeviceToken
    {
        return DeviceToken::factory()->create([
            'user_id' => $user->id,
            'app' => $app,
            'token' => $fcm,
            'device_name' => $name,
            'personal_access_token_id' => PersonalAccessToken::findToken($bearer)->getKey(),
        ]);
    }

    public function test_it_writes_a_bell_row_and_pushes_to_the_calling_install_only(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN, 'Pixel 7');
        $thisPhone = $this->register($technician, 'technician', $bearer, self::THIS_PHONE, 'Pixel 7');
        $this->register($technician, 'technician', $this->issueToken($technician, ApiTokenService::TECHNICIAN, 'Tablet'), self::OTHER_PHONE, 'Tablet');

        $response = $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)->assertStatus(202);

        $notification = DatabaseNotification::query()->sole();
        $response->assertExactJson(['data' => [
            'notification_id' => $notification->id,
            'device' => ['id' => $thisPhone->public_id, 'name' => 'Pixel 7'],
            'wait_seconds' => 30,
        ]]);
        $this->assertTrue(Str::isUuid($notification->id));
        $this->assertSame(User::class, $notification->notifiable_type);
        $this->assertSame($technician->id, (int) $notification->notifiable_id);
        $this->assertSame('push_test', $notification->data['type']);

        $this->assertSame([self::THIS_PHONE], $this->pushedTokens(), 'the second install of the same user was not pushed');
        $push = $this->onlyPush();
        $this->assertSame('push_test', $push['data']['type']);
        $this->assertSame($notification->id, $push['data']['notification_id']);
        $this->assertSame('Notifikasi uji', $push['notification']['title']);

        $bell = $this->api('GET', 'notifications', [], $bearer)->assertOk()->json('data.0');
        $this->assertSame(['push_test', 'Notifikasi uji', "Notifikasi uji dari {$technician->company->name}."], [$bell['type'], $bell['title'], $bell['message']]);
        $this->assertNull($bell['subject']);
    }

    public function test_the_customer_app_notifies_the_customer_record(): void
    {
        [$user, $customer] = $this->customerLogin();
        $bearer = $this->issueToken($user, ApiTokenService::CUSTOMER);
        $this->register($user, 'customer', $bearer, self::THIS_PHONE);

        $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)->assertStatus(202);

        $notification = DatabaseNotification::query()->sole();
        $this->assertSame(Customer::class, $notification->notifiable_type);
        $this->assertSame($customer->id, (int) $notification->notifiable_id);
        $this->assertSame('push_test', $notification->data['type']);
        $this->assertSame([self::THIS_PHONE], $this->pushedTokens());
    }

    public function test_a_token_of_another_session_is_not_registered(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);
        $this->register($technician, 'technician', $this->issueToken($technician, ApiTokenService::TECHNICIAN, 'Tablet'), self::OTHER_PHONE);
        $stranger = $this->technicianLogin();
        $this->register($stranger, 'technician', $this->issueToken($stranger, ApiTokenService::TECHNICIAN), 'stranger-fcm-token-0003');

        foreach ([self::OTHER_PHONE, 'stranger-fcm-token-0003', 'never-registered-0004'] as $token) {
            $this->api('POST', 'me/push-test', ['token' => $token], $bearer)
                ->assertStatus(409)
                ->assertJsonPath('code', 'push_token_not_registered');
        }

        $this->assertSame(0, DatabaseNotification::query()->count());
        $this->assertSame([], $this->multicasts);
    }

    public function test_push_off_is_unavailable(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);
        $this->register($technician, 'technician', $bearer, self::THIS_PHONE);
        Config::set('firebase.projects.app.credentials', null);

        $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)
            ->assertStatus(503)
            ->assertJsonPath('code', 'push_unavailable');

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_the_body_is_validated_and_a_sign_in_is_required(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);

        $this->api('POST', 'me/push-test', [], $bearer)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('token');
        $this->api('POST', 'me/push-test', ['token' => Str::random(4097)], $bearer)->assertStatus(422)->assertJsonValidationErrors('token');
        $this->api('POST', 'me/push-test', ['token' => ['x']], $bearer)->assertStatus(422)->assertJsonValidationErrors('token');
        $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE])->assertUnauthorized();
    }

    public function test_it_allows_three_a_minute(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);
        $this->register($technician, 'technician', $bearer, self::THIS_PHONE);

        foreach (range(1, 3) as $_) {
            $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)->assertStatus(202);
        }

        $blocked = $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->assertCount(3, $this->multicasts, 'the fourth call pushed nothing');
        $this->assertSame(3, DatabaseNotification::query()->count());

        $this->travel(61)->seconds();
        $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)->assertStatus(202);
    }

    public function test_it_needs_an_idempotency_key_and_replays_once(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);
        $this->register($technician, 'technician', $bearer, self::THIS_PHONE);
        $body = ['token' => self::THIS_PHONE];

        $this->api('POST', 'me/push-test', $body, $bearer, ['Idempotency-Key' => null])
            ->assertStatus(428)
            ->assertJsonPath('code', 'idempotency_key_required');

        $key = (string) Str::uuid();
        $first = $this->api('POST', 'me/push-test', $body, $bearer, ['Idempotency-Key' => $key])->assertStatus(202);
        $replay = $this->api('POST', 'me/push-test', $body, $bearer, ['Idempotency-Key' => $key])->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $replay->json());
        $this->assertCount(1, $this->multicasts, 'the replay did not push again');
        $this->assertSame(1, DatabaseNotification::query()->count());

        $this->travel(61)->seconds();
        $this->api('POST', 'me/push-test', ['token' => self::OTHER_PHONE], $bearer, ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_a_successful_send_records_the_last_sent_time(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);
        $this->register($technician, 'technician', $bearer, self::THIS_PHONE);
        Cache::forget('push:last_sent_at');
        $this->travelTo(now()->startOfSecond());

        $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)->assertStatus(202);

        $this->assertSame(now()->toIso8601String(), Cache::get('push:last_sent_at'));
    }

    public function test_a_send_that_reaches_nobody_does_not_move_the_last_sent_time(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, ApiTokenService::TECHNICIAN);
        $this->register($technician, 'technician', $bearer, self::THIS_PHONE);
        Cache::forget('push:last_sent_at');
        $this->fcmUnavailable(self::THIS_PHONE);

        // The job fails (nothing delivered) so the queue retries it; the sync queue of a test rethrows into the channel's rescue().
        $this->api('POST', 'me/push-test', ['token' => self::THIS_PHONE], $bearer)->assertStatus(202);

        $this->assertNull(Cache::get('push:last_sent_at'));
    }
}
