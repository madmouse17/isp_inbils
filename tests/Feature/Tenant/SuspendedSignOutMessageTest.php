<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/** F11-D05: a person signed out because the platform suspended the company is told why on the login page. */
class SuspendedSignOutMessageTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    public function test_the_login_page_after_a_forced_sign_out_carries_the_reason_in_its_page_props(): void
    {
        $this->buildGroup();
        CompanyService::resetCache();
        $user = $this->groupUser($this->subA);
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        Company::query()->whereKey($this->subA->id)->update(['is_active' => false]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

        // The page reads this prop: the form's own errors stay empty until the form is sent.
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('errors.email', CompanyService::SUSPENDED_MESSAGE));
    }
}
