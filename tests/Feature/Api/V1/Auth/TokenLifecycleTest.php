<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Everything a signed-in app does with its own token: me, refresh, logout and the device list.
 */
class TokenLifecycleTest extends TestCase
{
    use EnrollsTwoFactor;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
    }

    public function test_me_describes_a_customer_without_secrets(): void
    {
        [$user, $customer] = $this->customerLogin(attributes: ['name' => 'Rina', 'email' => 'rina@example.test']);
        $this->enrollTwoFactor($user);

        $response = $this->api('GET', 'me', token: $this->issueToken($user, 'customer'))->assertOk();

        $this->assertEquals(['data' => [
            'id' => $user->id,
            'name' => 'Rina',
            'email' => 'rina@example.test',
            'app' => 'customer',
            'two_factor_enabled' => true,
            'company' => ['id' => $user->company_id, 'name' => $user->company->name],
            'customer' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'employee' => null,
            'phone' => $customer->phone,
        ]], $response->json());
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'token'] as $secret) {
            $response->assertJsonMissingPath('data.'.$secret);
        }
    }

    public function test_me_describes_a_technician(): void
    {
        $user = $this->technicianLogin(attributes: ['name' => 'Budi']);

        $this->api('GET', 'me', token: $this->issueToken($user, 'technician'))
            ->assertOk()
            ->assertJsonPath('data.app', 'technician')
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.employee.name', 'Budi')
            ->assertJsonStructure(['data' => ['employee' => ['name', 'phone']]]);
    }

    public function test_refresh_swaps_the_token_for_a_new_one_with_the_same_name_and_ability_and_a_fresh_expiry(): void
    {
        [$user] = $this->customerLogin();
        $old = $this->issueToken($user, 'customer', 'Pixel 8');
        $this->travel(80)->days();

        $response = $this->api('POST', 'auth/token/refresh', token: $old)->assertOk();

        $response->assertJsonPath('data.token_type', 'Bearer');
        $this->assertSame(['token', 'token_type', 'expires_at'], array_keys($response->json('data')));
        $new = $response->json('data.token');
        $this->assertNotSame($old, $new);
        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(now()->addDays(90)));

        $row = $user->tokens()->sole();
        $this->assertSame('Pixel 8', $row->name);
        $this->assertSame(['customer'], $row->abilities);
        $this->assertTrue($row->expires_at->equalTo(now()->addDays(90)));
        $this->api('GET', 'me', token: $old)->assertUnauthorized();
        $this->api('GET', 'me', token: $new)->assertOk();

        $vias = Activity::query()->where('description', 'api.token_issued')->orderBy('id')->get()->map(fn (Activity $a) => $a->properties['via'])->all();
        $this->assertSame(['login', 'refresh'], $vias);
    }

    public function test_a_technician_refresh_is_good_for_thirty_days(): void
    {
        $user = $this->technicianLogin();
        $old = $this->issueToken($user, 'technician');
        $this->travel(25)->days();

        $response = $this->api('POST', 'auth/token/refresh', token: $old)->assertOk();

        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(now()->addDays(30)));
    }

    public function test_a_refresh_that_cannot_finish_leaves_the_old_token_working_and_no_new_one(): void
    {
        $this->app->make(ExceptionHandler::class)->dontReport(RuntimeException::class);
        [$user] = $this->customerLogin();
        $old = $this->issueToken($user, 'customer');
        PersonalAccessToken::deleting(fn () => throw new RuntimeException('disk full'));

        $this->api('POST', 'auth/token/refresh', token: $old)->assertStatus(500)->assertJsonPath('code', 'server_error');

        $this->assertSame(1, $user->tokens()->count(), 'rolled back as one transaction');
        $this->api('GET', 'me', token: $old)->assertOk();
    }

    public function test_a_token_that_was_already_swapped_cannot_be_swapped_again(): void
    {
        [$user] = $this->customerLogin();
        $stale = PersonalAccessToken::findToken($this->issueToken($user, 'customer'));
        $service = app(ApiTokenService::class);
        $service->rotate($user->withAccessToken($stale), $stale);

        // The second of two refreshes racing on one token reaches the service with a model whose row is gone.
        $this->expectException(AuthenticationException::class);

        $service->rotate($user->withAccessToken($stale), $stale);
    }

    public function test_refresh_is_refused_once_the_user_lost_access_or_was_deactivated(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $technician->removeRole('technician');

        $this->api('POST', 'auth/token/refresh', token: $token)->assertForbidden()->assertJsonPath('code', 'app_not_allowed');
        $this->assertSame(1, $technician->tokens()->count());

        [$user] = $this->customerLogin();
        $gone = $this->issueToken($user, 'customer');
        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->api('POST', 'auth/token/refresh', token: $gone)->assertUnauthorized()->assertJsonPath('code', 'account_inactive');
    }

    public function test_refresh_without_a_valid_token_is_a_401(): void
    {
        $this->api('POST', 'auth/token/refresh')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->api('POST', 'auth/token/refresh', token: '999|not-a-token')->assertUnauthorized();
    }

    public function test_logout_ends_the_current_token_only(): void
    {
        [$user] = $this->customerLogin();
        $phone = $this->issueToken($user, 'customer', 'Pixel 8');
        $tablet = $this->issueToken($user, 'customer', 'Tablet');

        $this->api('POST', 'auth/logout', token: $phone)->assertNoContent();

        $this->api('GET', 'me', token: $phone)->assertUnauthorized();
        $this->api('GET', 'me', token: $tablet)->assertOk();
        $audit = Activity::query()->where('description', 'api.tokens_revoked')->sole();
        $this->assertSame('logout', $audit->properties['reason']);
        $this->assertSame(1, $audit->properties['count']);
    }

    public function test_logout_still_works_for_a_user_who_lost_access_to_the_app(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $technician->removeRole('technician');

        $this->api('POST', 'auth/logout', token: $token)->assertNoContent();

        $this->assertSame(0, $technician->tokens()->count());
    }

    public function test_logout_with_a_dead_token_is_a_401(): void
    {
        [$user] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $this->travel(91)->days();

        $this->api('POST', 'auth/logout', token: $token)->assertUnauthorized();
    }

    public function test_the_device_list_shows_the_users_live_tokens_and_marks_the_one_in_use(): void
    {
        [$user] = $this->customerLogin();
        [$other] = $this->customerLogin();
        $phone = $this->issueToken($user, 'customer', 'Pixel 8');
        $this->issueToken($user, 'customer', 'Tablet');
        $user->createToken('Dead phone', ['customer'], now()->subMinute());
        $this->issueToken($other, 'customer', 'Not mine');
        $this->api('GET', 'me', token: $phone)->assertOk();

        $response = $this->api('GET', 'devices', token: $phone)->assertOk();

        $devices = collect($response->json('data'));
        $this->assertSame(['Tablet', 'Pixel 8'], $devices->pluck('name')->all(), 'newest first, nobody else\'s, nothing expired');
        foreach ($devices as $device) {
            $this->assertSame(['id', 'name', 'app', 'last_used_at', 'created_at', 'expires_at', 'current'], array_keys($device));
            $this->assertSame('customer', $device['app']);
            $this->assertTrue(Carbon::parse($device['expires_at'])->equalTo(now()->addDays(90)));
        }
        $this->assertSame([false, true], $devices->pluck('current')->all());
        $this->assertNotNull($devices->firstWhere('name', 'Pixel 8')['last_used_at']);
        $this->assertNull($devices->firstWhere('name', 'Tablet')['last_used_at']);
        $this->assertStringNotContainsString('token', collect($devices->first())->keys()->implode(','));
    }

    public function test_a_user_with_both_apps_sees_both_tokens(): void
    {
        [$user] = $this->customerLogin();
        $user->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'status' => 'active']);
        $this->issueToken($user, 'technician', 'Work phone');
        $own = $this->issueToken($user, 'customer', 'Home phone');

        $apps = $this->api('GET', 'devices', token: $own)->assertOk()->json('data.*.app');

        $this->assertEqualsCanonicalizing(['customer', 'technician'], $apps);
    }

    public function test_a_user_revokes_another_of_their_devices(): void
    {
        [$user] = $this->customerLogin();
        $phone = $this->issueToken($user, 'customer', 'Pixel 8');
        $lost = $this->issueToken($user, 'customer', 'Lost tablet');
        $lostId = PersonalAccessToken::findToken($lost)->id;

        $this->api('DELETE', "devices/{$lostId}", token: $phone)->assertNoContent();

        $this->api('GET', 'me', token: $lost)->assertUnauthorized();
        $this->api('GET', 'me', token: $phone)->assertOk();
        $audit = Activity::query()->where('description', 'api.tokens_revoked')->sole();
        $this->assertSame('device_revoked', $audit->properties['reason']);
    }

    public function test_a_user_may_revoke_the_device_in_use_too(): void
    {
        [$user] = $this->customerLogin();
        $phone = $this->issueToken($user, 'customer');

        $this->api('DELETE', 'devices/'.PersonalAccessToken::findToken($phone)->id, token: $phone)->assertNoContent();

        $this->api('GET', 'me', token: $phone)->assertUnauthorized();
    }

    public function test_someone_elses_token_and_unknown_ids_are_a_404_and_stay_untouched(): void
    {
        [$user] = $this->customerLogin();
        [$other] = $this->customerLogin();
        $mine = $this->issueToken($user, 'customer');
        $theirs = $this->issueToken($other, 'customer');
        $theirsId = PersonalAccessToken::findToken($theirs)->id;

        $this->api('DELETE', "devices/{$theirsId}", token: $mine)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->api('DELETE', 'devices/99999999', token: $mine)->assertNotFound();
        $this->api('DELETE', 'devices/abc', token: $mine)->assertNotFound();

        $this->api('GET', 'me', token: $theirs)->assertOk();
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_revoking_a_device_needs_an_idempotency_key_and_a_retry_still_answers_204(): void
    {
        [$user] = $this->customerLogin();
        $phone = $this->issueToken($user, 'customer');
        $lostId = PersonalAccessToken::findToken($this->issueToken($user, 'customer', 'Lost'))->id;

        $this->api('DELETE', "devices/{$lostId}", token: $phone, headers: ['Idempotency-Key' => null])->assertStatus(428);

        $key = (string) Str::uuid();
        $this->api('DELETE', "devices/{$lostId}", token: $phone, headers: ['Idempotency-Key' => $key])->assertNoContent();
        $this->api('DELETE', "devices/{$lostId}", token: $phone, headers: ['Idempotency-Key' => $key])
            ->assertNoContent()
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->api('DELETE', "devices/{$lostId}", token: $phone)->assertNotFound();
    }
}
