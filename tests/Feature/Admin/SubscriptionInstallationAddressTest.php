<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

/**
 * The installation address of a subscription is one of the addresses of its own customer: another customer's,
 * another company's and a deleted one are refused, when the subscription is created and when it is updated.
 */
class SubscriptionInstallationAddressTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const MESSAGE = 'Pilih salah satu alamat milik pelanggan ini.';

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
    }

    private function update(ServiceSubscription $subscription, string $addressId): TestResponse
    {
        return $this->actingAs($this->admin)
            ->put(route('admin.subscriptions.update', $subscription), ['installation_address_id' => $addressId]);
    }

    private function store(Customer $customer, string $addressId): TestResponse
    {
        $package = ServicePackageFactory::new()->create(['company_id' => $this->company->id]);

        return $this->actingAs($this->admin)->post(route('admin.customers.subscriptions.store', $customer), [
            'service_package_id' => $package->public_id,
            'installation_address_id' => $addressId,
            'billing_day' => 5,
        ]);
    }

    private function addressOf(Customer $customer): CustomerAddress
    {
        return CustomerAddress::factory()->create(['company_id' => $customer->company_id, 'customer_id' => $customer->id]);
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['company_id' => $this->company->id]);
    }

    /** The installation address of a subscription of another customer of the same company. */
    private function addressOfAnotherCustomer(): string
    {
        return $this->publicIdOfAddress($this->subscriptionIn($this->company)->installation_address_id);
    }

    /** The installation address of a subscription of another company. */
    private function addressOfAnotherCompany(): string
    {
        return $this->publicIdOfAddress($this->subscriptionIn(Company::factory()->create(['is_active' => true]))->installation_address_id);
    }

    private function deletedAddressOf(Customer $customer): string
    {
        $address = $this->addressOf($customer);
        $address->delete();

        return $address->public_id;
    }

    private function publicIdOfAddress(int $addressId): string
    {
        return (string) CustomerAddress::withoutCompany()->whereKey($addressId)->value('public_id');
    }

    public function test_update_refuses_an_address_of_another_customer_of_the_company(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $before = $subscription->installation_address_id;

        $this->update($subscription, $this->addressOfAnotherCustomer())
            ->assertSessionHasErrors(['installation_address_id' => self::MESSAGE]);

        $this->assertSame($before, $this->freshSubscription($subscription)->installation_address_id);
    }

    public function test_update_refuses_an_address_of_another_company(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        $this->update($subscription, $this->addressOfAnotherCompany())
            ->assertSessionHasErrors(['installation_address_id' => self::MESSAGE]);
    }

    public function test_update_refuses_a_deleted_address_of_the_same_customer(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        $this->update($subscription, $this->deletedAddressOf($subscription->customer))
            ->assertSessionHasErrors(['installation_address_id' => self::MESSAGE]);
    }

    public function test_update_moves_the_subscription_to_another_address_of_its_customer(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $second = $this->addressOf($subscription->customer);

        $this->update($subscription, $second->public_id)->assertSessionHasNoErrors();

        $this->assertSame($second->id, $this->freshSubscription($subscription)->installation_address_id);
    }

    public function test_update_refuses_an_explicit_null_but_omission_preserves_the_address(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $before = $subscription->installation_address_id;

        $this->actingAs($this->admin)
            ->put(route('admin.subscriptions.update', $subscription), ['installation_address_id' => null])
            ->assertSessionHasErrors('installation_address_id');

        $this->actingAs($this->admin)
            ->put(route('admin.subscriptions.update', $subscription), ['notes' => 'Alamat tidak diubah'])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, $this->freshSubscription($subscription)->installation_address_id);
    }

    public function test_cross_company_subscription_update_returns_not_found_regardless_of_lock_state(): void
    {
        $otherCompany = Company::factory()->create(['is_active' => true]);
        $unlocked = $this->subscriptionIn($otherCompany);
        $locked = $this->subscriptionIn($otherCompany);
        WorkOrderFactory::new()->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $locked->customer_id,
            'subscription_id' => $locked->id,
            'status' => 'assigned',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.subscriptions.update', $unlocked), ['notes' => 'probe'])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->put(route('admin.subscriptions.update', $locked), ['notes' => 'probe'])
            ->assertNotFound();
    }

    public function test_store_refuses_an_address_of_another_customer_of_the_company(): void
    {
        $customer = $this->customer();

        $this->store($customer, $this->addressOfAnotherCustomer())
            ->assertSessionHasErrors(['installation_address_id' => self::MESSAGE]);

        $this->assertSame(0, ServiceSubscription::query()->where('customer_id', $customer->id)->count());
    }

    public function test_store_refuses_an_address_of_another_company(): void
    {
        $customer = $this->customer();

        $this->store($customer, $this->addressOfAnotherCompany())
            ->assertSessionHasErrors(['installation_address_id' => self::MESSAGE]);

        $this->assertSame(0, ServiceSubscription::query()->where('customer_id', $customer->id)->count());
    }

    public function test_store_refuses_a_deleted_address_of_the_chosen_customer(): void
    {
        $customer = $this->customer();

        $this->store($customer, $this->deletedAddressOf($customer))
            ->assertSessionHasErrors(['installation_address_id' => self::MESSAGE]);

        $this->assertSame(0, ServiceSubscription::query()->where('customer_id', $customer->id)->count());
    }

    public function test_store_creates_the_subscription_at_an_address_of_the_chosen_customer(): void
    {
        $customer = $this->customer();
        $address = $this->addressOf($customer);

        $this->store($customer, $address->public_id)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.customers.subscriptions.index', $customer));

        $this->assertSame(
            $address->id,
            ServiceSubscription::query()->where('customer_id', $customer->id)->sole()->installation_address_id,
        );
    }
}
