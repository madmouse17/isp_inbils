<?php

namespace Tests\Feature\Push;

use App\Models\Core\DeviceToken;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * POST and DELETE api/v1/me/device-token: an install hands the server the FCM token it should be pushed on. The row is
 * bound to the Sanctum token that registered it, so every way that token goes away also ends the pushes to the install.
 */
class DeviceTokenApiTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const FCM_TOKEN = 'dQw4w9WgXcQ:APA91bFcmTokenOfOnePhone-0001';

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return ['token' => self::FCM_TOKEN, 'app' => 'technician', 'platform' => 'android', 'device_name' => 'Pixel 7', ...$overrides];
    }

    private function accessTokenIdOf(string $bearer): int
    {
        return (int) PersonalAccessToken::findToken($bearer)->getKey();
    }

    public function test_an_install_registers_its_token_and_registering_it_again_updates_the_same_row(): void
    {
        $technician = $this->technicianLogin();
        $bearer = $this->issueToken($technician, 'technician', 'Budi phone');

        $created = $this->api('POST', 'me/device-token', $this->body(), $bearer)->assertCreated();
        $id = $created->json('data.id');

        $this->assertSame(['id'], array_keys($created->json('data')), 'only the id leaves the server');
        $this->assertStringNotContainsString(self::FCM_TOKEN, $created->getContent());

        $row = DeviceToken::query()->sole();
        $this->assertSame($id, $row->public_id);
        $this->assertSame($technician->id, $row->user_id);
        $this->assertSame('technician', $row->app);
        $this->assertSame('android', $row->platform);
        $this->assertSame('Pixel 7', $row->device_name);
        $this->assertSame($this->accessTokenIdOf($bearer), $row->personal_access_token_id);
        $this->assertSame(self::FCM_TOKEN, $row->token);
        $this->assertNotNull($row->last_seen_at);

        $this->travel(5)->minutes();
        $this->api('POST', 'me/device-token', $this->body(['device_name' => 'Pixel 7 Pro']), $bearer)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertSame('Pixel 7 Pro', $row->fresh()->device_name);
        $this->assertTrue($row->fresh()->last_seen_at->isAfter($row->last_seen_at));
    }

    public function test_without_a_device_name_the_name_of_the_sign_in_is_kept(): void
    {
        $bearer = $this->issueToken($this->technicianLogin(), 'technician', 'Budi phone');

        $this->api('POST', 'me/device-token', ['token' => self::FCM_TOKEN, 'app' => 'technician', 'platform' => 'android'], $bearer)->assertCreated();

        $this->assertSame('Budi phone', DeviceToken::query()->sole()->device_name);
    }

    public function test_the_same_phone_signed_in_as_another_user_takes_the_token_over(): void
    {
        $first = $this->technicianLogin();
        $second = $this->technicianLogin($first->company);
        $this->api('POST', 'me/device-token', $this->body(), $this->issueToken($first, 'technician'))->assertCreated();

        $bearer = $this->issueToken($second, 'technician');
        $this->api('POST', 'me/device-token', $this->body(), $bearer)->assertOk();

        $row = DeviceToken::query()->sole();
        $this->assertSame($second->id, $row->user_id);
        $this->assertSame($this->accessTokenIdOf($bearer), $row->personal_access_token_id);
        $this->assertSame(0, DeviceToken::query()->where('user_id', $first->id)->count(), 'the previous user no longer receives pushes on it');
    }

    public function test_a_customer_registers_for_the_customer_app_and_a_user_of_both_apps_keeps_one_token_per_install(): void
    {
        [$user] = $this->customerLogin();
        $user->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'status' => 'active']);

        $this->api('POST', 'me/device-token', $this->body(['app' => 'customer', 'token' => 'customer-install-token']), $this->issueToken($user, 'customer'))->assertCreated();
        $this->api('POST', 'me/device-token', $this->body(['app' => 'technician', 'token' => 'technician-install-token']), $this->issueToken($user, 'technician'))->assertCreated();

        $this->assertSame(['customer', 'technician'], DeviceToken::query()->where('user_id', $user->id)->orderBy('app')->pluck('app')->all());
        $this->assertSame(2, DeviceToken::query()->count());
    }

    public function test_the_app_in_the_body_must_be_the_app_of_the_bearer_token(): void
    {
        [$customer] = $this->customerLogin();
        $technician = $this->technicianLogin();

        $this->api('POST', 'me/device-token', $this->body(['app' => 'technician']), $this->issueToken($customer, 'customer'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app']);
        $this->api('POST', 'me/device-token', $this->body(['app' => 'customer']), $this->issueToken($technician, 'technician'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app']);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_the_body_is_validated(): void
    {
        $bearer = $this->issueToken($this->technicianLogin(), 'technician');

        foreach ([
            'token' => [['token' => null], ['token' => ''], ['token' => str_repeat('a', 4097)], ['token' => ['a']]],
            'app' => [['app' => null], ['app' => 'admin']],
            'platform' => [['platform' => null], ['platform' => 'ios']],
            'device_name' => [['device_name' => str_repeat('d', 101)]],
        ] as $field => $cases) {
            foreach ($cases as $case) {
                $this->api('POST', 'me/device-token', $this->body($case), $bearer)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$field]);
            }
        }

        $this->api('POST', 'me/device-token', $this->body(['token' => str_repeat('a', 4096), 'device_name' => str_repeat('d', 100)]), $bearer)->assertCreated();
    }

    public function test_the_endpoints_need_a_sign_in_and_an_idempotency_key(): void
    {
        $bearer = $this->issueToken($this->technicianLogin(), 'technician');

        $this->api('POST', 'me/device-token', $this->body())->assertUnauthorized();
        $this->api('DELETE', 'me/device-token', ['token' => self::FCM_TOKEN])->assertUnauthorized();
        $this->api('POST', 'me/device-token', $this->body(), $bearer, ['Idempotency-Key' => null])->assertStatus(428);
        $this->api('DELETE', 'me/device-token', ['token' => self::FCM_TOKEN], $bearer, ['Idempotency-Key' => null])->assertStatus(428);

        $key = (string) Str::uuid();
        $this->api('POST', 'me/device-token', $this->body(), $bearer, ['Idempotency-Key' => $key])->assertCreated();
        $this->api('POST', 'me/device-token', $this->body(), $bearer, ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_a_user_forgets_only_their_own_token_and_always_gets_a_204(): void
    {
        $owner = $this->technicianLogin();
        $stranger = $this->technicianLogin($owner->company);
        $ownerBearer = $this->issueToken($owner, 'technician');
        $this->api('POST', 'me/device-token', $this->body(), $ownerBearer)->assertCreated();

        $this->api('DELETE', 'me/device-token', ['token' => self::FCM_TOKEN], $this->issueToken($stranger, 'technician'))->assertNoContent();
        $this->assertSame(1, DeviceToken::query()->count(), 'a token held by someone else is left alone');

        $this->api('DELETE', 'me/device-token', ['token' => 'nobody-has-this-token'], $ownerBearer)->assertNoContent();
        $this->api('DELETE', 'me/device-token', ['token' => self::FCM_TOKEN], $ownerBearer)->assertNoContent();
        $this->assertSame(0, DeviceToken::query()->count());

        $this->api('DELETE', 'me/device-token', ['token' => self::FCM_TOKEN], $ownerBearer)->assertNoContent();
        $this->api('DELETE', 'me/device-token', [], $ownerBearer)->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }

    public function test_logging_out_ends_the_pushes_of_that_install_only(): void
    {
        $technician = $this->technicianLogin();
        $phone = $this->issueToken($technician, 'technician', 'Phone');
        $tablet = $this->issueToken($technician, 'technician', 'Tablet');
        $this->api('POST', 'me/device-token', $this->body(['token' => 'phone-token']), $phone)->assertCreated();
        $this->api('POST', 'me/device-token', $this->body(['token' => 'tablet-token']), $tablet)->assertCreated();

        $this->api('POST', 'auth/logout', token: $phone)->assertNoContent();

        $this->assertSame(['tablet-token'], DeviceToken::query()->pluck('token')->all());
    }

    public function test_revoking_a_device_or_changing_the_password_or_deactivating_the_user_ends_the_pushes(): void
    {
        $technician = $this->technicianLogin();
        $phone = $this->issueToken($technician, 'technician', 'Phone');
        $stolen = $this->issueToken($technician, 'technician', 'Stolen');
        $this->api('POST', 'me/device-token', $this->body(['token' => 'phone-token']), $phone)->assertCreated();
        $this->api('POST', 'me/device-token', $this->body(['token' => 'stolen-token']), $stolen)->assertCreated();

        $this->api('DELETE', 'devices/'.PersonalAccessToken::findToken($stolen)->public_id, token: $phone)->assertNoContent();
        $this->assertSame(['phone-token'], DeviceToken::query()->pluck('token')->all());

        $technician->update(['password' => 'Sturdy-New-2026!']);
        $this->assertSame(0, DeviceToken::query()->count());

        $again = $this->issueToken($technician, 'technician');
        $this->api('POST', 'me/device-token', $this->body(['token' => 'again-token']), $again)->assertCreated();
        $technician->update(['is_active' => false]);
        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_a_suspended_company_ends_the_pushes_of_its_users(): void
    {
        $technician = $this->technicianLogin();
        $this->api('POST', 'me/device-token', $this->body(), $this->issueToken($technician, 'technician'))->assertCreated();

        $technician->company->update(['is_active' => false]);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_refreshing_the_sign_in_keeps_the_registration(): void
    {
        $technician = $this->technicianLogin();
        $old = $this->issueToken($technician, 'technician', 'Phone');
        $this->api('POST', 'me/device-token', $this->body(), $old)->assertCreated();

        $new = $this->api('POST', 'auth/token/refresh', token: $old)->assertOk()->json('data.token');

        $this->assertNull(PersonalAccessToken::findToken($old));
        $row = DeviceToken::query()->sole();
        $this->assertSame($this->accessTokenIdOf($new), $row->personal_access_token_id);
        $this->assertSame(self::FCM_TOKEN, $row->token);
    }

    public function test_the_token_is_hidden_from_serialization_and_unique_through_its_hash(): void
    {
        $technician = $this->technicianLogin();
        $this->api('POST', 'me/device-token', $this->body(), $this->issueToken($technician, 'technician'))->assertCreated();

        $row = DeviceToken::query()->sole();
        $this->assertSame(hash('sha256', self::FCM_TOKEN), $row->token_hash);
        $this->assertArrayNotHasKey('token', $row->toArray());
        $this->assertArrayNotHasKey('token_hash', $row->toArray());
        $this->assertSame(substr(self::FCM_TOKEN, -6), $row->suffix());
        $this->assertInstanceOf(User::class, $row->user);
    }
}
