<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IsolirPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @param  array<string, mixed>  $attributes */
    private function company(array $attributes = []): Company
    {
        return Company::factory()->create($attributes + [
            'name' => 'PT Fiber Nusantara',
            'code' => 'FIBER',
            'phone' => '+62 22 5550 1234',
            'email' => 'cs@fiber.example.test',
            'website' => 'https://fiber.example.test',
            'address' => 'Jl. Rahasia No. 1, Bandung',
            'settings' => ['bank_account_info' => 'BCA 000-111-222', 'invoice_due_days' => 14],
            'is_active' => true,
        ]);
    }

    public function test_a_known_company_code_shows_only_the_four_public_fields_and_the_login_link(): void
    {
        $company = $this->company();

        $response = $this->get(route('isolir.show', $company->code));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('company', ['name' => 'PT Fiber Nusantara', 'phone' => '+62 22 5550 1234', 'email' => 'cs@fiber.example.test', 'website' => 'https://fiber.example.test'])
            ->where('loginUrl', route('login')));
        $props = $response->inertiaProps();
        $this->assertSame(['name', 'phone', 'email', 'website'], array_keys($props['company']));
        $this->assertSame('/isolir/FIBER', parse_url(route('isolir.show', 'FIBER'), PHP_URL_PATH));
    }

    public function test_contact_fields_may_be_missing(): void
    {
        $company = $this->company(['code' => 'BARE', 'phone' => null, 'email' => null, 'website' => null]);

        $this->get(route('isolir.show', $company->code))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('company', ['name' => 'PT Fiber Nusantara', 'phone' => null, 'email' => null, 'website' => null]));
    }

    public function test_an_unknown_company_code_is_a_404(): void
    {
        $this->company();

        $this->get('/isolir/NOPE')->assertNotFound();
        $this->get('/isolir/FIBER-OTHER')->assertNotFound();
    }

    public function test_an_inactive_company_is_a_404_like_an_unknown_one(): void
    {
        $dormant = $this->company(['code' => 'DORM', 'is_active' => false]);

        $this->get(route('isolir.show', $dormant->code))->assertNotFound();
    }

    public function test_the_page_exposes_nothing_else_about_the_company_or_anyone(): void
    {
        $company = $this->company();
        Customer::factory()->create(['company_id' => $company->id, 'name' => 'Pelanggan Rahasia']);

        $response = $this->get(route('isolir.show', $company->code));

        $encoded = json_encode($response->inertiaProps());
        foreach (['Jl. Rahasia', 'BCA 000-111-222', 'Pelanggan Rahasia', 'bank_account_info'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertNull($response->inertiaProps()['auth']['user'] ?? null);
        $this->assertNull($response->inertiaPage()['props']['companyHierarchy'] ?? null);
    }

    public function test_it_is_public_and_needs_no_session(): void
    {
        $company = $this->company();

        $this->assertGuest();
        $this->get(route('isolir.show', $company->code))->assertOk();
        $this->assertGuest();
    }

    public function test_subscribers_behind_one_nat_address_are_not_cut_off_by_the_old_sixty_a_minute_limit(): void
    {
        $company = $this->company();

        foreach (range(1, 61) as $_) {
            $this->get(route('isolir.show', $company->code))->assertOk();
        }
    }

    public function test_code_guessing_is_throttled_per_address_and_never_blocks_a_real_code(): void
    {
        $company = $this->company();

        foreach (range(1, 20) as $guess) {
            $this->get('/isolir/GUESS'.$guess)->assertNotFound();
        }

        $this->get('/isolir/GUESS21')->assertStatus(429);
        $this->get(route('isolir.show', $company->code))->assertOk();
    }

    public function test_the_route_is_outside_the_admin_prefix_and_uses_the_named_limiter(): void
    {
        $route = app('router')->getRoutes()->getByName('isolir.show');

        $this->assertStringStartsWith('isolir/', $route->uri());
        $this->assertContains('throttle:isolir', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
    }
}
