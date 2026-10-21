<?php

namespace Tests\Feature;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

class AppOnlyRedirectTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
    }

    private function technician(): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($user, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    private function customer(): User
    {
        [$user] = $this->customerLogin($this->admin->company, ['email_verified_at' => now(), 'is_active' => true]);

        return $user;
    }

    public function test_a_technician_only_login_is_sent_to_the_app_page_from_every_admin_area(): void
    {
        $technician = $this->technician();

        foreach (['/admin/dashboard', '/admin/my-work', '/admin/tickets', '/admin/spk', '/admin/invoices', '/admin/inventory/requests'] as $url) {
            $this->actingAs($technician)->get($url)->assertRedirect(route('app-only'));
        }
    }

    public function test_a_customer_only_login_is_sent_to_the_app_page_from_every_admin_area(): void
    {
        $customer = $this->customer();

        foreach (['/admin/dashboard', '/admin/tickets', '/admin/invoices', '/admin/notifications'] as $url) {
            $this->actingAs($customer)->get($url)->assertRedirect(route('app-only'));
        }
    }

    public function test_the_midtrans_return_page_is_not_redirected_for_a_customer(): void
    {
        $this->actingAs($this->customer())->get(route('portal.payments.return'))->assertOk();
        $this->get('/pembayaran/selesai')->assertOk();
    }

    public function test_a_technician_who_also_holds_staff_is_not_redirected(): void
    {
        $technician = $this->technician();
        $this->grantRole($technician, 'staff');

        $this->actingAs($technician)->get('/admin/dashboard')->assertOk();
        $this->actingAs($technician)->get(route('app-only'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_an_admin_is_not_redirected_and_is_sent_away_from_the_app_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($this->admin)->get(route('app-only'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('app-only'))->assertRedirect(route('login'));
        $this->get('/admin/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_shared_app_only_flag_matches_the_redirect_rule(): void
    {
        $dual = $this->technician();
        $this->grantRole($dual, 'staff');

        foreach ([[$this->technician(), true], [$this->customer(), true], [$dual, false], [$this->admin, false]] as [$user, $expected]) {
            $this->actingAs($user)->get(route('profile.edit'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('appOnly', $expected));
        }
    }

    public function test_the_app_page_names_the_app_and_hides_the_store_link_until_the_owner_sets_it(): void
    {
        $this->actingAs($this->technician())->get(route('app-only'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AppOnly')
                ->where('role', 'technician')
                ->where('appName', 'Inbils Teknisi')
                ->where('playStoreUrl', null));

        $this->actingAs($this->customer())->get(route('app-only'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('role', 'customer')
                ->where('appName', 'Inbils Pelanggan')
                ->where('playStoreUrl', null));
    }

    public function test_a_login_with_no_role_is_told_to_ask_for_web_access(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($user)->get('/admin/dashboard')->assertRedirect(route('app-only'));
        $this->get(route('app-only'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AppOnly')
                ->where('role', null)
                ->where('appName', null)
                ->where('playStoreUrl', null));
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('appOnly', true));
    }

    public function test_a_role_without_app_web_is_sent_to_the_app_page_until_app_web_is_granted(): void
    {
        $user = $this->grantPermissions(
            User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]),
            ['dashboard.view', 'customer.view'],
        );

        $this->actingAs($user)->get('/admin/dashboard')->assertRedirect(route('app-only'));
        $this->get(route('app-only'))->assertInertia(fn (Assert $page) => $page->where('role', null));

        $this->grantPermissions($user, ['app.web']);

        $this->get('/admin/dashboard')->assertOk();
    }

    public function test_a_login_marked_for_both_apps_is_shown_the_technician_app(): void
    {
        $both = $this->technician();
        $this->grantRole($both, 'customer');

        $this->actingAs($both)->get(route('app-only'))
            ->assertInertia(fn (Assert $page) => $page->where('role', 'technician')->where('appName', 'Inbils Teknisi'));
    }

    public function test_the_app_page_links_the_store_listing_of_the_role(): void
    {
        SettingService::set('play_store_url_technician', 'https://play.google.com/store/apps/details?id=id.inbils.technician');
        SettingService::set('play_store_url_customer', 'https://play.google.com/store/apps/details?id=id.inbils.customer');

        $this->actingAs($this->technician())->get(route('app-only'))
            ->assertInertia(fn (Assert $page) => $page->where('playStoreUrl', 'https://play.google.com/store/apps/details?id=id.inbils.technician'));
        $this->actingAs($this->customer())->get(route('app-only'))
            ->assertInertia(fn (Assert $page) => $page->where('playStoreUrl', 'https://play.google.com/store/apps/details?id=id.inbils.customer'));
    }

    public function test_a_store_url_that_is_not_on_google_play_is_treated_as_empty(): void
    {
        foreach (['http://play.google.com/store/apps/details?id=x', 'https://evil.example/play.google.com/', 'javascript:alert(1)'] as $url) {
            SettingService::set('play_store_url_technician', $url);

            $this->actingAs($this->technician())->get(route('app-only'))
                ->assertInertia(fn (Assert $page) => $page->where('playStoreUrl', null));
        }
    }

    public function test_the_removed_portal_pages_answer_404(): void
    {
        $customer = $this->customer();

        foreach (['/portal/invoices', '/portal/invoices/1', '/portal/password'] as $url) {
            $this->actingAs($customer)->get($url)->assertNotFound();
        }
    }

    public function test_every_admin_route_carries_the_redirect(): void
    {
        $missing = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/') || $route->uri() === 'admin')
            ->reject(fn ($route) => in_array('redirect.app.only', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri());

        $this->assertSame([], $missing->values()->all());
    }
}
