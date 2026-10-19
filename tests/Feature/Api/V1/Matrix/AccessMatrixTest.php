<?php

namespace Tests\Feature\Api\V1\Matrix;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may knock on each api/v1 door at all, walked from route:list so a new route cannot skip the fence:
 * no token, revoked token, inactive user, the other app's token, and a login that does not belong to its company.
 */
class AccessMatrixTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    public function test_the_walk_covers_every_registered_route(): void
    {
        $this->assertCount(61, $this->apiRoutes(), 'a route was added or removed: extend the owner fixtures in BuildsMatrixWorld::realIds');
    }

    public function test_a_call_without_a_token_is_a_401_everywhere_but_on_the_public_auth_endpoints(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if ($this->isPublic($route)) {
                continue;
            }

            $this->api($this->method($route), $this->urlOf($route, real: true))
                ->assertUnauthorized()
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_a_revoked_token_is_a_401_on_every_authenticated_route(): void
    {
        $customerToken = $this->issueToken($this->peerCustomerUser, 'customer');
        $technicianToken = $this->issueToken($this->peerTechnician, 'technician');
        $this->peerCustomerUser->tokens()->delete();
        $this->peerTechnician->tokens()->delete();

        foreach ($this->apiRoutes() as $route) {
            if ($this->isPublic($route)) {
                continue;
            }

            $token = $this->fenceOf($route) === 'technician' ? $technicianToken : $customerToken;
            $this->api($this->method($route), $this->urlOf($route, real: true), token: $token)
                ->assertUnauthorized()
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_an_inactive_user_is_a_401_account_inactive_everywhere_except_logout(): void
    {
        User::query()->whereKey([$this->peerCustomerUser->id, $this->peerTechnician->id])->update(['is_active' => false]);

        foreach ($this->apiRoutes() as $route) {
            if ($this->isPublic($route) || $this->shortName($route) === 'auth.logout') {
                continue;
            }

            // The guard deletes the token it caught, so every call brings a new one.
            $technician = $this->fenceOf($route) === 'technician';
            $token = ($technician ? $this->peerTechnician : $this->peerCustomerUser)->createToken('Old phone', [$technician ? 'technician' : 'customer'], now()->addDay())->plainTextToken;

            $this->api($this->method($route), $this->urlOf($route, real: true), token: $token)
                ->assertUnauthorized()
                ->assertJsonPath('code', 'account_inactive');
        }
    }

    public function test_logout_still_works_for_an_inactive_user(): void
    {
        $token = $this->issueToken($this->peerCustomerUser, 'customer');
        User::query()->whereKey($this->peerCustomerUser->id)->update(['is_active' => false]);

        $this->api('POST', 'auth/logout', token: $token)->assertNoContent();
        $this->assertSame(0, $this->peerCustomerUser->tokens()->count());
    }

    public function test_the_other_apps_token_is_a_403_on_every_fenced_route_and_reaches_every_shared_route(): void
    {
        $customer = $this->issueToken($this->peerCustomerUser, 'customer');
        $technician = $this->issueToken($this->peerTechnician, 'technician');

        foreach ($this->apiRoutes() as $route) {
            $fence = $this->fenceOf($route);
            if ($fence === null) {
                continue;
            }

            $this->api($this->method($route), $this->urlOf($route, real: true), token: $fence === 'customer' ? $technician : $customer)
                ->assertForbidden()
                ->assertJsonPath('code', 'forbidden');
        }

        $this->api('GET', 'me', token: $customer)->assertOk();
        $this->api('GET', 'me', token: $technician)->assertOk();
        $this->api('GET', 'notifications', token: $customer)->assertOk();
        $this->api('GET', 'notifications', token: $technician)->assertOk();
        $this->api('GET', 'devices', token: $customer)->assertOk();
        $this->api('GET', 'devices', token: $technician)->assertOk();
    }

    public function test_a_login_whose_customer_record_belongs_to_another_company_is_a_403_on_every_authenticated_route(): void
    {
        [$user, $customer] = $this->customerLogin($this->companyA);
        $customer->forceFill(['company_id' => $this->companyB->id])->save();
        $token = $user->createToken('Moved', ['customer'], now()->addDay())->plainTextToken; // issue() would refuse it

        foreach ($this->apiRoutes() as $route) {
            if ($this->isPublic($route) || $this->shortName($route) === 'auth.logout') {
                continue;
            }

            if ($this->fenceOf($route) === 'technician') {
                continue; // the ability fence answers first and is covered above
            }

            $this->api($this->method($route), $this->urlOf($route, real: true), token: $token)
                ->assertForbidden()
                ->assertJsonPath('code', 'app_not_allowed');
        }
    }

    public function test_a_technician_whose_employee_profile_belongs_to_another_company_is_a_403_on_every_technician_route(): void
    {
        $user = $this->technicianLogin($this->companyA);
        $user->employeeProfile()->update(['company_id' => $this->companyB->id]);
        $token = $user->createToken('Moved', ['technician'], now()->addDay())->plainTextToken;

        $routes = array_filter($this->apiRoutes(), fn ($route): bool => $this->fenceOf($route) === 'technician');
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->api($this->method($route), $this->urlOf($route, real: true), token: $token)
                ->assertForbidden()
                ->assertJsonPath('code', 'app_not_allowed');
        }
    }
}
