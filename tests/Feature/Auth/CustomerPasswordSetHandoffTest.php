<?php

namespace Tests\Feature\Auth;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * F4-U14: a customer who has just set a password has nothing to do on the web: the sign-in page says so, offers the app and
 * keeps the e-mail. Staff land on the sign-in page as before.
 */
class CustomerPasswordSetHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($user, 'customer', $company);
        Customer::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

        return $user;
    }

    /** @return array<string, string> */
    private function resetPayload(User $user): array
    {
        return [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'a-long-new-secret-1',
            'password_confirmation' => 'a-long-new-secret-1',
        ];
    }

    public function test_a_customer_lands_on_the_sign_in_page_with_the_app_and_the_email(): void
    {
        SettingService::set('play_store_url_customer', 'https://play.google.com/store/apps/details?id=id.inbils.customer');
        $user = $this->customerUser(Company::factory()->create(['is_active' => true]));

        $this->post('/reset-password', $this->resetPayload($user))->assertRedirect(route('login'));

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('status', 'Kata sandi tersimpan. Masuk lewat aplikasi Inbils.')
            ->where('customerHandoff', ['email' => $user->email, 'appUrl' => 'https://play.google.com/store/apps/details?id=id.inbils.customer']));
    }

    public function test_without_a_store_listing_the_page_still_says_where_to_sign_in(): void
    {
        $user = $this->customerUser(Company::factory()->create(['is_active' => true]));

        $this->post('/reset-password', $this->resetPayload($user));

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('customerHandoff', ['email' => $user->email, 'appUrl' => null]));
    }

    public function test_the_hand_off_shows_once(): void
    {
        $user = $this->customerUser(Company::factory()->create(['is_active' => true]));
        $this->post('/reset-password', $this->resetPayload($user));
        $this->get(route('login'));

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('status', null)
            ->where('customerHandoff', null));
    }

    public function test_staff_get_the_plain_status_and_no_hand_off(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($staff, 'staff', $company);

        $this->post('/reset-password', $this->resetPayload($staff))->assertRedirect(route('login'));

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('status', __('passwords.reset'))
            ->where('customerHandoff', null));
    }
}
