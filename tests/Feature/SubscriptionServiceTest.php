<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_generates_next_code_in_current_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $address = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);
        $servicePackage = ServicePackageFactory::new()->create(['company_id' => $company->id, 'price_mrc' => 150_000]);
        $otherCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);
        $otherPackage = ServicePackageFactory::new()->create(['company_id' => $otherCompany->id]);

        ServiceSubscription::factory()->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'service_package_id' => $otherPackage->id,
            'code' => 'SUB-'.now()->year.'-00009',
        ]);
        ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => $servicePackage->id,
            'installation_address_id' => $address->id,
            'code' => 'SUB-'.now()->year.'-00003',
        ]);

        $this->actingAs($user);

        $subscription = SubscriptionService::create([
            'customer_id' => $customer->id,
            'service_package_id' => $servicePackage->id,
            'installation_address_id' => $address->id,
            'billing_day' => 5,
            'otc_installation_fee' => 25_000,
            'contract_months' => 12,
        ]);

        $this->assertSame($company->id, $subscription->company_id);
        $this->assertSame('SUB-'.now()->year.'-00004', $subscription->code);
        $this->assertSame('150000.00', $subscription->mrc_amount);
    }
}
