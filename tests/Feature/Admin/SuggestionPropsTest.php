<?php

namespace Tests\Feature\Admin;

use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\WithoutAppOnlyRedirect;

/** Props the forms read to offer a value the system computes from its own data. */
class SuggestionPropsTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
    }

    public function test_customer_create_sends_pop_coordinates_and_package_prices(): void
    {
        Location::factory()->create(['company_id' => $this->company->id, 'code' => 'POP-1', 'name' => 'POP Satu', 'type' => 'pop', 'path' => 'POP-1', 'lat' => -6.2, 'lng' => 106.8]);
        Location::factory()->create(['company_id' => $this->company->id, 'code' => 'POP-2', 'name' => 'POP Dua', 'type' => 'pop', 'path' => 'POP-2', 'lat' => null, 'lng' => null]);
        ServicePackageFactory::new()->create(['company_id' => $this->company->id, 'price_mrc' => 250000, 'price_otc' => 150000, 'is_active' => true]);

        $this->get(route('admin.customers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locations.0.lat', null)
                ->where('locations.0.lng', null)
                ->where('locations.1.lat', -6.2)
                ->where('locations.1.lng', 106.8)
                ->where('packages.0.price_mrc', fn ($value) => (float) $value === 250000.0)
                ->where('packages.0.price_otc', fn ($value) => (float) $value === 150000.0));
    }

    public function test_ticket_create_categories_carry_their_default_priority(): void
    {
        TicketCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Gangguan Total', 'code' => 'GT', 'default_sla_hours' => 4, 'default_priority' => 'urgent', 'is_active' => true]);

        $this->get(route('admin.tickets.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('categories.data.0.default_priority', 'urgent'));
    }

    public function test_subscription_form_addresses_flag_the_primary_one(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $customer = $subscription->customer;
        $primary = CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'is_primary' => true]);

        $this->get(route('admin.customers.subscriptions.index', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.data', fn ($addresses) => collect($addresses)->firstWhere('id', $primary->public_id)['is_primary'] === true
                    && collect($addresses)->where('is_primary', true)->count() === 1));
    }

    public function test_invoice_create_sends_the_company_due_days(): void
    {
        $this->get(route('admin.invoices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('due_days', 14));

        $this->company->update(['settings' => array_merge($this->company->settings ?? [], ['invoice_due_days' => 30])]);

        $this->get(route('admin.invoices.create'))
            ->assertInertia(fn (Assert $page) => $page->where('due_days', 30));
    }

    public function test_network_account_page_sends_the_customer_code(): void
    {
        $subscription = $this->makeSubscription($this->company);

        $this->get(route('admin.subscriptions.network-account.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscription.customer.code', $subscription->customer->code));
    }
}
