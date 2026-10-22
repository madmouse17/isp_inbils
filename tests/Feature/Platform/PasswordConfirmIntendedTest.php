<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F11-D01: confirming the operator password lands on a page of the panel, never on a stale tenant address. */
class PasswordConfirmIntendedTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_a_post_stopped_by_the_step_up_does_not_leave_itself_as_the_place_to_return_to(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        $this->asOperator(false)
            ->post(route('platform.companies.exports.store', $tenant['root']->public_id), ['confirmation_code' => 'ALP'])
            ->assertRedirect(route('platform.password.confirm'));

        // A POST is not an address to return to by GET: only the page it came from may be kept.
        $this->assertStringNotContainsString('/exports', (string) session('url.intended'));
    }

    public function test_a_stale_tenant_address_is_not_followed_after_confirming(): void
    {
        $this->asOperator(false)
            ->withSession(['url.intended' => 'https://tenant.example.test/dashboard'])
            ->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()])
            ->assertRedirect(route('platform.dashboard'));
    }

    public function test_a_dialog_returns_to_the_page_it_was_opened_on(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $page = route('platform.companies.show', $tenant['root']->public_id);

        $this->asOperator(false)
            ->from($page)
            ->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()])
            ->assertRedirect($page);
    }

    public function test_the_panel_shares_how_long_the_confirmation_still_counts(): void
    {
        $this->asOperator(false)->get(route('platform.dashboard'))
            ->assertInertia(fn ($page) => $page->where('passwordConfirmedUntil', null));

        $this->asOperator()->get(route('platform.dashboard'))
            ->assertInertia(fn ($page) => $page->where('passwordConfirmedUntil', fn ($until): bool => is_string($until) && strtotime($until) > time()));
    }
}
