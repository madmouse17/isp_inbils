<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\InteractsWithMobileApi;

/** F12-D05: a person whose company the platform suspended learns why at sign-in in the app, and gets no token. */
class SuspendedTenantLoginTest extends TestCase
{
    use BuildsCompanyGroup;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_an_api_login_in_a_suspended_company_says_the_company_is_suspended(): void
    {
        $this->buildGroup();
        CompanyService::resetCache();
        [$user] = $this->customerLogin($this->subA);
        // A bulk update skips the model events, the way the suspension of a tenant does for its subsidiaries.
        Company::query()->whereKey($this->subA->id)->update(['is_active' => false]);
        $tokens = $user->tokens()->count();

        $this->api('POST', 'auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel 8',
            'app' => 'customer',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', CompanyService::SUSPENDED_MESSAGE);

        $this->assertSame($tokens, $user->tokens()->count());
    }
}
