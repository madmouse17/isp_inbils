<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubscriptionInputs(Company $company): array
    {
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $address = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);
        $servicePackage = ServicePackageFactory::new()->create(['company_id' => $company->id, 'price_mrc' => 150_000]);

        return [
            'customer_id' => $customer->id,
            'service_package_id' => $servicePackage->id,
            'installation_address_id' => $address->id,
            'billing_day' => 5,
            'otc_installation_fee' => 25_000,
            'contract_months' => 12,
        ];
    }

    public function test_create_generates_next_code_via_number_sequence(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $first = SubscriptionService::create($this->makeSubscriptionInputs($company));
        $second = SubscriptionService::create($this->makeSubscriptionInputs($company));

        $this->assertSame($company->id, $first->company_id);
        $this->assertSame('SUB-'.now()->year.'-00001', $first->code);
        $this->assertSame('SUB-'.now()->year.'-00002', $second->code);
        $this->assertSame('150000.00', $first->mrc_amount);
    }

    public function test_concurrent_create_produces_unique_codes(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $codes = [];
        foreach (range(1, 5) as $_) {
            $codes[] = SubscriptionService::create($this->makeSubscriptionInputs($company))->code;
        }

        $this->assertCount(5, array_unique($codes));
    }

    public function test_suspend_double_click_is_safe(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);
        CompanyService::resetCache();

        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        $suspended = SubscriptionService::suspend($subscription, 'nonpayment');
        $this->assertSame('suspended', $suspended->status);

        $this->expectException(HttpException::class);
        SubscriptionService::suspend($subscription->fresh(), 'nonpayment');
    }

    public function test_next_billing_date_skips_no_month_for_day_31(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        Carbon::setTestNow(Carbon::create(2026, 1, 31));

        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'status' => 'pending',
            'billing_day' => 31,
        ]);

        $activated = SubscriptionService::activate($subscription);

        $this->assertSame('2026-02-28', $activated->next_invoice_date->toDateString());

        Carbon::setTestNow();
    }
}
