<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * POST /api/v1/auth/login for users without a second factor: who gets which token, and every refusal.
 */
class LoginTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
    }

    public function test_a_customer_signs_in_and_gets_a_token_for_the_customer_app_valid_for_ninety_days(): void
    {
        [$user, $customer] = $this->customerLogin(attributes: ['email' => 'rina@example.test', 'name' => 'Rina']);

        $response = $this->login($user, 'customer', 'Pixel 8')->assertOk();

        $response->assertJsonPath('data.token_type', 'Bearer');
        $this->assertEquals([
            'id' => $user->public_id,
            'name' => 'Rina',
            'email' => 'rina@example.test',
            'app' => 'customer',
            'two_factor_enabled' => false,
            'company' => [
                'id' => Company::query()->findOrFail($user->company_id)->public_id,
                'name' => Company::query()->findOrFail($user->company_id)->name,
                'logo_url' => null,
                'brand_color' => null,
                'phone' => null,
                'whatsapp' => null,
                'email' => null,
                'website' => null,
                'support_hours' => null,
            ],
            'customer' => ['id' => $customer->public_id, 'code' => $customer->code, 'name' => $customer->name],
            'employee' => null,
            'phone' => $customer->phone,
        ], $response->json('data.user'));
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(now()->addDays(90)));

        $token = PersonalAccessToken::query()->sole();
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertSame(User::class, $token->tokenable_type);
        $this->assertSame('Pixel 8', $token->name);
        $this->assertSame(['customer'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(90)));
        $this->assertSame(hash('sha256', explode('|', $response->json('data.token'), 2)[1]), $token->token, 'only the hash is stored');

        $this->api('GET', 'me', token: $response->json('data.token'))->assertOk()->assertJsonPath('data.id', $user->public_id);
        $this->assertGuest('web');
        $response->assertHeaderMissing('Set-Cookie');
    }

    public function test_a_technician_signs_in_and_gets_a_token_for_the_technician_app_valid_for_thirty_days(): void
    {
        $user = $this->technicianLogin(attributes: ['email' => 'budi@example.test', 'name' => 'Budi']);
        $phone = EmployeeProfile::withoutCompany()->where('user_id', $user->id)->value('phone');

        $response = $this->login($user, 'technician', 'Galaxy A54')->assertOk();

        $response->assertJsonPath('data.user.app', 'technician')
            ->assertJsonPath('data.user.customer', null)
            ->assertJsonPath('data.user.employee', ['name' => 'Budi', 'phone' => $phone]);
        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(now()->addDays(30)));
        $this->assertSame(['technician'], PersonalAccessToken::query()->sole()->abilities);
    }

    public function test_login_updates_last_login_and_audits_the_issue_without_the_token(): void
    {
        [$user] = $this->customerLogin();
        $this->assertNull($user->last_login_at);

        $response = $this->login($user, 'customer', 'Pixel 8')->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
        $audit = Activity::query()->where('description', 'api.token_issued')->sole();
        $this->assertSame('security', $audit->log_name);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame($user->company_id, (int) $audit->company_id, 'the company lives in its column, not in the properties');
        // Keyed, not canonicalized: sorting ints together with '127.0.0.1' is not a total order in PHP 8, so a
        // canonicalized comparison passes or fails by the size of the ids.
        $this->assertEquals([
            'user_id' => $user->id,
            'token_id' => PersonalAccessToken::query()->sole()->id,
            'app' => 'customer',
            'device_name' => 'Pixel 8',
            'ip' => '127.0.0.1',
            'via' => 'login',
        ], $audit->properties->all());
        $this->assertStringNotContainsString(explode('|', $response->json('data.token'), 2)[1], json_encode($audit->properties));
    }

    public function test_a_wrong_password_an_unknown_address_and_an_inactive_user_get_the_same_answer(): void
    {
        [$active] = $this->customerLogin();
        [$inactive] = $this->customerLogin(attributes: ['is_active' => false]);

        $answers = [
            $this->login($active, 'customer', 'Pixel 8', ['password' => 'wrong-password']),
            $this->login($active, 'customer', 'Pixel 8', ['email' => 'nobody@example.test']),
            $this->login($inactive, 'customer', 'Pixel 8'),
        ];

        foreach ($answers as $answer) {
            $answer->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['email']);
            $this->assertSame($answer->headers->get('X-Request-Id'), $answer->json('request_id'));
        }
        // Each answer names its own request, and nothing else tells the three cases apart.
        $bodies = array_map(fn (TestResponse $answer): array => Arr::except($answer->json(), ['request_id']), $answers);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($bodies[0], $bodies[2]);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_sixth_attempt_for_one_address_is_a_429_with_retry_after_even_with_the_right_password(): void
    {
        [$user] = $this->customerLogin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login($user, 'customer', 'Pixel 8', ['password' => 'wrong-password'])->assertStatus(422);
        }

        $response = $this->login($user, 'customer', 'Pixel 8');

        $response->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_failed_attempt_budget_is_shared_with_the_web_login(): void
    {
        [$user] = $this->customerLogin();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->login($user, 'customer', 'Pixel 8', ['password' => 'wrong-password'])->assertStatus(422);

        $this->login($user, 'customer', 'Pixel 8')->assertStatus(429);
        $this->assertTrue(RateLimiter::tooManyAttempts(strtolower($user->email).'|127.0.0.1', 5));
    }

    public function test_a_good_login_gives_the_budget_back(): void
    {
        [$user] = $this->customerLogin();
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->login($user, 'customer', 'Pixel 8', ['password' => 'wrong-password'])->assertStatus(422);
        }

        $this->login($user, 'customer', 'Pixel 8')->assertOk();

        $this->assertSame(0, RateLimiter::attempts(strtolower($user->email).'|127.0.0.1'));
    }

    public function test_the_device_name_and_the_app_are_required_and_bounded(): void
    {
        [$user] = $this->customerLogin();
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $this->api('POST', 'auth/login', $credentials)->assertStatus(422)->assertJsonValidationErrors(['device_name', 'app']);
        $this->api('POST', 'auth/login', $credentials + ['device_name' => str_repeat('a', 101), 'app' => 'customer'])
            ->assertStatus(422)->assertJsonValidationErrors(['device_name']);
        $this->api('POST', 'auth/login', $credentials + ['device_name' => 'Pixel 8', 'app' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors(['app']);
        $this->api('POST', 'auth/login', $credentials + ['device_name' => ['x'], 'app' => 'customer'])
            ->assertStatus(422)->assertJsonValidationErrors(['device_name']);
        $this->api('POST', 'auth/login', $credentials + ['device_name' => str_repeat('é', 100), 'app' => 'customer'])->assertOk();

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_control_characters_are_stripped_from_the_device_name(): void
    {
        [$user] = $this->customerLogin();

        $this->login($user, 'customer', "Pixel\x00 8\r\n\t")->assertOk();

        $this->assertSame('Pixel 8', PersonalAccessToken::query()->sole()->name);
    }

    public function test_a_device_name_that_is_only_control_characters_is_refused(): void
    {
        [$user] = $this->customerLogin();

        $this->login($user, 'customer', "\x00\r\n\t")->assertStatus(422)->assertJsonValidationErrors(['device_name']);
    }

    public function test_a_login_flagged_for_a_password_reset_is_refused_with_its_own_code(): void
    {
        [$user] = $this->customerLogin();
        $user->forceFill(['must_change_password' => true])->save();

        $this->login($user, 'customer', 'Pixel 8')->assertForbidden()->assertJsonPath('code', 'password_reset_required');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[DataProvider('loginsWithoutAccess')]
    public function test_a_user_who_may_not_use_the_app_is_refused_and_gets_no_token(string $case, string $app): void
    {
        $user = $this->{$case}();

        $this->login($user, $app, 'Pixel 8')->assertForbidden()->assertJsonPath('code', 'app_not_allowed');

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(0, Activity::query()->where('description', 'api.token_issued')->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function loginsWithoutAccess(): array
    {
        return [
            'customer role without a customer record' => ['customerWithoutRecord', 'customer'],
            'customer with an unverified e-mail' => ['unverifiedCustomer', 'customer'],
            'customer without a company' => ['customerWithoutCompany', 'customer'],
            'technician app for a customer' => ['plainCustomer', 'technician'],
            'customer app for a technician' => ['plainTechnician', 'customer'],
            'technician without an employee profile' => ['technicianWithoutProfile', 'technician'],
            'technician with an unverified e-mail' => ['unverifiedTechnician', 'technician'],
            'admin who is no field employee' => ['plainAdmin', 'technician'],
        ];
    }

    public function test_a_user_who_is_both_customer_and_technician_signs_in_to_each_app_with_its_own_token(): void
    {
        [$user, $record] = $this->customerLogin();
        $this->grantRole($user, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'status' => 'active']);

        $customer = $this->login($user, 'customer', 'Pixel 8')->assertOk();
        $technician = $this->login($user, 'technician', 'Pixel 8')->assertOk();

        $customer->assertJsonPath('data.user.employee', null)->assertJsonPath('data.user.customer.id', $record->public_id);
        $technician->assertJsonPath('data.user.customer', null);
        $this->assertSame(2, PersonalAccessToken::query()->count());
    }

    /** @param  array<string, mixed>  $override */
    private function login(User $user, string $app, string $device, array $override = []): TestResponse
    {
        return $this->api('POST', 'auth/login', array_merge([
            'email' => $user->email,
            'password' => 'password',
            'device_name' => $device,
            'app' => $app,
        ], $override));
    }

    private function customerWithoutRecord(): User
    {
        [$user, $customer] = $this->customerLogin();
        $customer->forceDelete();

        return $user;
    }

    private function unverifiedCustomer(): User
    {
        return $this->customerLogin(attributes: ['email_verified_at' => null])[0];
    }

    private function customerWithoutCompany(): User
    {
        [$user] = $this->customerLogin();
        $user->forceFill(['company_id' => null])->save();

        return $user;
    }

    private function plainCustomer(): User
    {
        return $this->customerLogin()[0];
    }

    private function plainTechnician(): User
    {
        return $this->technicianLogin();
    }

    private function technicianWithoutProfile(): User
    {
        $user = $this->technicianLogin();
        EmployeeProfile::withoutCompany()->where('user_id', $user->id)->forceDelete();

        return $user;
    }

    private function unverifiedTechnician(): User
    {
        return $this->technicianLogin(attributes: ['email_verified_at' => null]);
    }

    private function plainAdmin(): User
    {
        $this->seedApiRoles();
        $user = User::factory()->create(['company_id' => $this->apiCompany()->id]);
        $this->grantRole($user, 'admin');

        return $user;
    }
}
