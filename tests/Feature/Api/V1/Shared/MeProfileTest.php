<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * What the two apps let a signed-in user do to their own account: rename themselves and change the phone number
 * of the person behind the login, and change the password (which signs every phone out).
 */
class MeProfileTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Sturdy-New-2026!';

    public function test_a_customer_renames_the_login_and_changes_the_contact_phone_but_not_the_billing_name(): void
    {
        [$user, $customer] = $this->customerLogin(attributes: ['name' => 'Rina', 'email' => 'rina@example.test']);
        $customer->forceFill(['name' => 'PT Rina Net', 'phone' => '0811111'])->save();
        $token = $this->issueToken($user, 'customer');

        $this->api('GET', 'me', token: $token)->assertOk()->assertJsonPath('data.phone', '0811111');
        $this->api('PATCH', 'me', ['name' => 'Rina Baru', 'phone' => '+62 812-3456 (7890)'], $token)
            ->assertOk()
            ->assertJsonPath('data.name', 'Rina Baru')
            ->assertJsonPath('data.phone', '+62 812-3456 (7890)')
            ->assertJsonPath('data.email', 'rina@example.test')
            ->assertJsonPath('data.customer.name', 'PT Rina Net')
            ->assertJsonPath('data.app', 'customer');

        $this->assertSame('Rina Baru', $user->fresh()->name);
        $this->assertSame('+62 812-3456 (7890)', $customer->fresh()->phone);
        $this->assertSame('PT Rina Net', $customer->fresh()->name);
        $this->api('GET', 'me', token: $token)->assertOk()->assertJsonPath('data.phone', '+62 812-3456 (7890)');
    }

    public function test_a_technician_renames_the_login_and_changes_the_employee_phone(): void
    {
        $technician = $this->technicianLogin(attributes: ['name' => 'Budi']);
        $this->setPhone($technician, '0822222');
        $token = $this->issueToken($technician, 'technician');

        $this->api('PATCH', 'me', ['name' => 'Budi Santoso', 'phone' => '08123456789'], $token)
            ->assertOk()
            ->assertJsonPath('data.name', 'Budi Santoso')
            ->assertJsonPath('data.phone', '08123456789')
            ->assertJsonPath('data.employee.name', 'Budi Santoso')
            ->assertJsonPath('data.employee.phone', '08123456789')
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.app', 'technician');

        $this->assertSame('Budi Santoso', $technician->fresh()->name);
        $this->assertSame('08123456789', $this->phoneOf($technician));
    }

    public function test_only_what_is_sent_changes_and_a_null_phone_clears_it(): void
    {
        $technician = $this->technicianLogin(attributes: ['name' => 'Budi']);
        $this->setPhone($technician, '0822222');
        $token = $this->issueToken($technician, 'technician');

        $this->api('PATCH', 'me', ['name' => 'Budi Baru'], $token)->assertOk()->assertJsonPath('data.phone', '0822222');
        $this->assertSame('0822222', $this->phoneOf($technician));

        $this->api('PATCH', 'me', ['phone' => '0833333'], $token)->assertOk()->assertJsonPath('data.name', 'Budi Baru');
        $this->assertSame('Budi Baru', $technician->fresh()->name);

        $this->api('PATCH', 'me', [], $token)->assertOk()->assertJsonPath('data.name', 'Budi Baru')->assertJsonPath('data.phone', '0833333');

        $this->api('PATCH', 'me', ['phone' => null], $token)->assertOk()->assertJsonPath('data.phone', null);
        $this->assertNull($this->phoneOf($technician));
        $this->assertSame('Budi Baru', $technician->fresh()->name);
    }

    public function test_the_login_email_and_every_other_field_stay_out_of_reach(): void
    {
        [$user, $customer] = $this->customerLogin(attributes: ['email' => 'rina@example.test']);
        $token = $this->issueToken($user, 'customer');

        $this->api('PATCH', 'me', [
            'name' => 'Rina Baru',
            'email' => 'attacker@example.test',
            'is_active' => false,
            'company_id' => 999,
            'customer_id' => 999,
            'tax_id' => 'X',
            'code' => 'HACK',
        ], $token)->assertOk();

        $this->assertSame('rina@example.test', $user->fresh()->email);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame($user->company_id, $user->fresh()->company_id);
        $this->assertSame($customer->code, $customer->fresh()->code);
        $this->assertNotSame('X', $customer->fresh()->tax_id);
    }

    public function test_the_name_and_phone_are_validated(): void
    {
        $technician = $this->technicianLogin(attributes: ['name' => 'Budi']);
        $this->setPhone($technician, '0822222');
        $token = $this->issueToken($technician, 'technician');

        foreach ([['name' => ''], ['name' => null], ['name' => str_repeat('a', 256)], ['name' => ['x']]] as $body) {
            $this->api('PATCH', 'me', $body, $token)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name'], 'errors');
        }
        foreach ([['phone' => 'call me'], ['phone' => '08;DROP'], ['phone' => str_repeat('1', 51)], ['phone' => ['0812']]] as $body) {
            $this->api('PATCH', 'me', $body, $token)->assertStatus(422)->assertJsonValidationErrors(['phone'], 'errors');
        }

        $this->assertSame('Budi', $technician->fresh()->name);
        $this->assertSame('0822222', $this->phoneOf($technician));
    }

    // The employee row is company-scoped, and a test has no signed-in user to give it a company before the first request.
    private function setPhone(User $technician, ?string $phone): void
    {
        EmployeeProfile::withoutCompany()->where('user_id', $technician->id)->update(['phone' => $phone]);
    }

    private function phoneOf(User $technician): ?string
    {
        return EmployeeProfile::withoutCompany()->where('user_id', $technician->id)->value('phone');
    }

    public function test_changing_the_password_signs_every_device_out_and_the_new_password_logs_in(): void
    {
        [$user] = $this->customerLogin(attributes: ['email' => 'rina@example.test']);
        $token = $this->issueToken($user, 'customer', 'Pixel 8');
        $otherDevice = $this->issueToken($user, 'customer', 'Old tablet');

        $this->api('POST', 'me/password', $this->passwordBody(), $token)->assertNoContent();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->getAuthPassword()));
        $this->assertSame(0, $user->tokens()->count());
        $this->api('GET', 'me', token: $token)->assertUnauthorized();
        $this->api('GET', 'me', token: $otherDevice)->assertUnauthorized();
        $audit = Activity::query()->where('description', 'api.tokens_revoked')->sole();
        $this->assertSame('password_changed', $audit->getExtraProperty('reason'));
        $this->assertSame(2, $audit->getExtraProperty('count'));

        $this->api('POST', 'auth/login', ['email' => 'rina@example.test', 'password' => self::NEW_PASSWORD, 'app' => 'customer', 'device_name' => 'Pixel 8'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
        $this->api('POST', 'auth/login', ['email' => 'rina@example.test', 'password' => 'password', 'app' => 'customer', 'device_name' => 'Pixel 8'])->assertStatus(422);
    }

    public function test_a_technician_changes_the_password_too(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');

        $this->api('POST', 'me/password', $this->passwordBody(), $token)->assertNoContent();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $technician->fresh()->getAuthPassword()));
        $this->api('GET', 'me', token: $token)->assertUnauthorized();
    }

    public function test_a_wrong_current_password_or_a_bad_new_one_changes_nothing(): void
    {
        [$user] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $before = $user->fresh()->getAuthPassword();

        $this->api('POST', 'me/password', $this->passwordBody(['current_password' => 'not-the-password']), $token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['current_password'], 'errors');

        foreach ([
            ['password_confirmation' => 'Different-2026!'],
            ['password' => 'short', 'password_confirmation' => 'short'],
            ['password' => null, 'password_confirmation' => null],
        ] as $override) {
            $this->api('POST', 'me/password', $this->passwordBody($override), $token)->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');
        }
        $this->api('POST', 'me/password', [], $token)->assertStatus(422)->assertJsonValidationErrors(['current_password', 'password'], 'errors');

        $this->assertSame($before, $user->fresh()->getAuthPassword());
        $this->api('GET', 'me', token: $token)->assertOk();
    }

    public function test_guessing_the_current_password_is_capped_at_five_a_minute_with_the_webs_budget(): void
    {
        [$user] = $this->customerLogin();
        [$other] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $key = 'password-confirm|'.$user->id.'|127.0.0.1';
        $wrong = $this->passwordBody(['current_password' => 'guess']);

        for ($guess = 1; $guess <= 5; $guess++) {
            $this->api('POST', 'me/password', $wrong, $token)->assertStatus(422)->assertJsonValidationErrors(['current_password'], 'errors');
            $this->assertSame($guess, RateLimiter::attempts($key));
        }

        // The sixth is refused even with the right password, as on the web.
        $response = $this->api('POST', 'me/password', $this->passwordBody(), $token)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->getAuthPassword()));
        $this->api('POST', 'me/password', $this->passwordBody(), $this->issueToken($other, 'customer'))->assertNoContent();
    }

    public function test_the_right_current_password_gives_the_attempts_back(): void
    {
        [$user] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $key = 'password-confirm|'.$user->id.'|127.0.0.1';

        $this->api('POST', 'me/password', $this->passwordBody(['current_password' => 'guess']), $token)->assertStatus(422);
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->api('POST', 'me/password', $this->passwordBody(), $token)->assertNoContent();
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function passwordBody(array $override = []): array
    {
        return [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$override,
        ];
    }
}
