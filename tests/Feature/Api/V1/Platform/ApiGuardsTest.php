<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The guards every authenticated call passes: ability fence, active user, app access, re-checked on every request.
 * Tokens here are minted directly so a user can be made ineligible after the token exists.
 */
class ApiGuardsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'api.customer'])->prefix('api/v1/_probe')->get('customer', fn (Request $request) => [
            'customer_id' => $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE)?->id,
        ]);
        Route::middleware(['api', 'api.technician'])->prefix('api/v1/_probe')->get('technician', fn () => ['ok' => true]);
        Route::middleware(['api', 'api.customer'])->prefix('api/v1/_probe')->post('act', fn (Request $request) => response()->json(['key' => $request->attributes->get(EnforceIdempotency::KEY_ATTRIBUTE)], 201));
    }

    public function test_a_customer_token_reaches_customer_routes_with_the_customer_resolved_on_the_request(): void
    {
        [$user, $customer] = $this->customerLogin();

        $this->api('GET', '_probe/customer', token: $this->mint($user, 'customer'))
            ->assertOk()
            ->assertExactJson(['customer_id' => $customer->id]);
    }

    public function test_a_technician_token_reaches_technician_routes(): void
    {
        $user = $this->technicianLogin();

        $this->api('GET', '_probe/technician', token: $this->mint($user, 'technician'))->assertOk();
    }

    public function test_a_token_never_crosses_to_the_other_apps_routes(): void
    {
        [$customerUser] = $this->customerLogin();
        $technician = $this->technicianLogin();

        $this->api('GET', '_probe/technician', token: $this->mint($customerUser, 'customer'))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->api('GET', '_probe/customer', token: $this->mint($technician, 'technician'))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_a_user_who_is_both_customer_and_technician_holds_one_token_per_app(): void
    {
        [$user] = $this->customerLogin();
        $this->grantRole($user, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'status' => 'active']);

        $this->api('GET', '_probe/customer', token: $this->mint($user, 'customer'))->assertOk();
        $this->api('GET', '_probe/technician', token: $this->mint($user, 'technician'))->assertOk();
        $this->api('GET', '_probe/technician', token: $this->mint($user, 'customer'))->assertForbidden();
    }

    public function test_an_inactive_user_is_a_401_and_the_token_that_was_used_is_deleted(): void
    {
        [$user] = $this->customerLogin();
        $used = $this->mint($user, 'customer');
        $kept = $this->mint($user, 'customer');
        // A bulk update skips the model hooks, so only the per-request guard can notice.
        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->api('GET', 'me', token: $used)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'account_inactive');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(PersonalAccessToken::findToken($kept)?->id, $user->tokens()->sole()->id);
    }

    #[DataProvider('ineligibleLogins')]
    public function test_a_user_who_may_not_use_the_app_is_a_403_on_every_call(string $case, string $ability): void
    {
        $user = $this->{$case}();

        $this->api('GET', 'me', token: $this->mint($user, $ability))
            ->assertForbidden()
            ->assertJsonPath('code', 'app_not_allowed');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function ineligibleLogins(): array
    {
        return [
            'customer role without a customer record' => ['customerWithoutRecord', 'customer'],
            'customer record without the customer role' => ['customerWithoutRole', 'customer'],
            'customer record of another company' => ['customerOfAnotherCompany', 'customer'],
            'customer with an unverified e-mail' => ['unverifiedCustomer', 'customer'],
            'customer without a company' => ['customerWithoutCompany', 'customer'],
            'technician without an employee profile' => ['technicianWithoutProfile', 'technician'],
            'technician with an inactive profile' => ['technicianWithInactiveProfile', 'technician'],
            'technician profile of another company' => ['technicianProfileElsewhere', 'technician'],
            'technician with an unverified e-mail' => ['unverifiedTechnician', 'technician'],
            'technician token on a customer login' => ['plainCustomer', 'technician'],
            'customer token on a technician login' => ['plainTechnician', 'customer'],
            'a token with no app ability' => ['plainCustomer', 'reports'],
        ];
    }

    public function test_eligibility_is_checked_again_on_every_request(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->mint($technician, 'technician');
        $this->api('GET', 'me', token: $token)->assertOk();

        $this->dropRole($technician, 'technician');

        $this->api('GET', 'me', token: $token)->assertForbidden()->assertJsonPath('code', 'app_not_allowed');
    }

    public function test_a_login_flagged_for_a_password_reset_after_the_token_was_issued_is_refused(): void
    {
        [$user] = $this->customerLogin();
        $token = $this->mint($user, 'customer');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $this->api('GET', 'me', token: $token)->assertForbidden()->assertJsonPath('code', 'password_reset_required');
    }

    public function test_expired_and_deleted_tokens_are_a_401(): void
    {
        $user = $this->technicianLogin();
        $expired = $this->mint($user, 'technician');
        $deleted = $this->mint($user, 'technician');
        $user->tokens()->whereKey(PersonalAccessToken::findToken($deleted)->id)->delete();

        $this->api('GET', 'me', token: $deleted)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $this->travel(31)->days();

        $this->api('GET', 'me', token: $expired)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_sanctum_acting_as_passes_the_guards_so_the_endpoint_tests_of_the_other_tickets_can_use_it(): void
    {
        [$user, $customer] = $this->customerLogin();
        $key = (string) Str::uuid();

        Sanctum::actingAs($user, ['customer']);

        $this->getJson('/api/v1/_probe/customer')->assertOk()->assertExactJson(['customer_id' => $customer->id]);
        $this->getJson('/api/v1/_probe/technician')->assertForbidden();
        $this->postJson('/api/v1/_probe/act')->assertStatus(428);
        $this->postJson('/api/v1/_probe/act', [], ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('key', $key);
    }

    public function test_sanctum_acting_as_with_the_technician_ability_is_fenced_to_the_technician_app(): void
    {
        Sanctum::actingAs($this->technicianLogin(), ['technician']);

        $this->getJson('/api/v1/_probe/technician')->assertOk();
        $this->getJson('/api/v1/_probe/customer')->assertForbidden();
    }

    public function test_a_session_cookie_is_not_a_credential(): void
    {
        [$user] = $this->customerLogin();

        $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function mint(User $user, string $ability): string
    {
        return $user->createToken('Test phone', [$ability], now()->addDays(30))->plainTextToken;
    }

    private function customerWithoutRecord(): User
    {
        [$user, $customer] = $this->customerLogin();
        $customer->forceDelete();

        return $user;
    }

    private function customerWithoutRole(): User
    {
        [$user] = $this->customerLogin();
        $this->dropRole($user, 'customer');

        return $user;
    }

    private function customerOfAnotherCompany(): User
    {
        [$user, $customer] = $this->customerLogin();
        $customer->forceFill(['company_id' => $this->apiCompany()->id])->save();

        return $user;
    }

    private function unverifiedCustomer(): User
    {
        [$user] = $this->customerLogin(attributes: ['email_verified_at' => null]);

        return $user;
    }

    /** Takes the company's copy of a template away from the user, in the company he acts in. */
    private function dropRole(User $user, string $templateKey): void
    {
        CompanyService::runAs((int) $user->company_id, fn () => $user->removeRole($this->companyRoles((int) $user->company_id)[$templateKey]));
    }

    private function customerWithoutCompany(): User
    {
        [$user] = $this->customerLogin();
        $user->forceFill(['company_id' => null])->save();

        return $user;
    }

    private function technicianWithoutProfile(): User
    {
        $user = $this->technicianLogin();
        EmployeeProfile::withoutCompany()->where('user_id', $user->id)->forceDelete();

        return $user;
    }

    private function technicianWithInactiveProfile(): User
    {
        $user = $this->technicianLogin();
        EmployeeProfile::withoutCompany()->where('user_id', $user->id)->update(['status' => 'inactive']);

        return $user;
    }

    private function technicianProfileElsewhere(): User
    {
        $user = $this->technicianLogin();
        EmployeeProfile::withoutCompany()->where('user_id', $user->id)->update(['company_id' => $this->apiCompany()->id]);

        return $user;
    }

    private function unverifiedTechnician(): User
    {
        return $this->technicianLogin(attributes: ['email_verified_at' => null]);
    }

    private function plainCustomer(): User
    {
        return $this->customerLogin()[0];
    }

    private function plainTechnician(): User
    {
        return $this->technicianLogin();
    }
}
