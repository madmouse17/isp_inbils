<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use Illuminate\Database\Seeder;
use Modules\Service\Models\ServicePackage;

/**
 * Generic sample customers for a company that has none. DemoDataSeeder tells the whole story of the demo
 * company, so this one leaves a company that already has customers alone.
 */
class CustomerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = CompanyService::current() ?? Company::query()->first();

        if (! $company) {
            return;
        }

        // Tenant models fail closed without a company: a seeder names the company it seeds.
        CompanyService::runAs((int) $company->id, function () use ($company): void {
            if (Customer::query()->exists()) {
                return;
            }

            $this->seedCustomers($company);
        });
    }

    private function seedCustomers(Company $company): void
    {
        // 20 customers: 12 Individual + 8 Company
        $customers = collect();

        foreach (['Individual' => 12, 'Company' => 8] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $customers->push(Customer::factory()->create(['company_id' => $company->id, 'type' => $type]));
            }
        }

        // Each customer gets 1-2 addresses
        foreach ($customers as $customer) {
            CustomerAddress::factory()->create([
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'label' => 'Utama',
                'is_installation_point' => true,
                'is_primary' => true,
            ]);

            if (rand(0, 1)) {
                CustomerAddress::factory()->create([
                    'customer_id' => $customer->id,
                    'company_id' => $company->id,
                    'label' => 'Cabang',
                ]);
            }
        }

        $packages = ServicePackage::query()->get();

        if ($packages->isEmpty()) {
            return;
        }

        // 30 subscriptions: 20 active, 5 suspended, 3 pending, 2 terminated
        $statuses = [
            ...array_fill(0, 20, 'active'),
            ...array_fill(0, 5, 'suspended'),
            ...array_fill(0, 3, 'pending'),
            ...array_fill(0, 2, 'terminated'),
        ];

        shuffle($statuses);

        foreach ($statuses as $status) {
            $customer = $customers->random();
            $address = $customer->addresses()->first();
            $package = $packages->random();

            $data = [
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
                'installation_address_id' => $address->id,
                'status' => $status,
                'billing_day' => rand(1, 28),
                'mrc_amount' => $package->getAttribute('price_mrc'),
                'otc_installation_fee' => $package->getAttribute('price_otc'),
                'contract_months' => (int) $package->getAttribute('contract_min_months') > 0 ? (int) $package->getAttribute('contract_min_months') : null,
            ];

            if ($status === 'active') {
                $data['activation_date'] = now()->subDays(rand(30, 365))->toDateString();
                $data['next_invoice_date'] = now()->addDays(rand(1, 28))->toDateString();
            } elseif ($status === 'suspended') {
                $data['activation_date'] = now()->subDays(rand(60, 365))->toDateString();
            } elseif ($status === 'terminated') {
                $data['activation_date'] = now()->subDays(rand(180, 548))->toDateString();
                $data['terminated_at'] = now()->subDays(rand(1, 30));
                $data['terminated_reason'] = 'Service cancelled by customer';
            }

            SubscriptionService::create($data);
        }
    }
}
