<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Services\Core\CompanyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = CompanyService::current() ?? Company::query()->first();

        if (! $company) {
            return;
        }

        // Run within company context
        CompanyService::resetCache();

        // 20 customers: 12 Individual + 8 Company
        $customers = collect();

        for ($i = 0; $i < 12; $i++) {
            $customers->push(Customer::factory()->create([
                'company_id' => $company->id,
                'type' => 'Individual',
            ]));
        }

        for ($i = 0; $i < 8; $i++) {
            $customers->push(Customer::factory()->create([
                'company_id' => $company->id,
                'type' => 'Company',
            ]));
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

        // 30 subscriptions: 20 active, 5 suspended, 3 pending, 2 terminated
        $packageIds = DB::table('service_packages')->where('company_id', $company->id)->pluck('id')->toArray();

        if (empty($packageIds)) {
            return;
        }

        $statusDistribution = [
            ...array_fill(0, 20, 'active'),
            ...array_fill(0, 5, 'suspended'),
            ...array_fill(0, 3, 'pending'),
            ...array_fill(0, 2, 'terminated'),
        ];

        shuffle($statusDistribution);

        foreach ($statusDistribution as $status) {
            $customer = $customers->random();
            $address = $customer->addresses()->first();

            if (! $address) {
                continue;
            }

            $packageId = $packageIds[array_rand($packageIds)];
            $pkg = DB::table('service_packages')->where('id', $packageId)->first();

            $data = [
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'service_package_id' => $packageId,
                'installation_address_id' => $address->id,
                'code' => 'SUB-'.date('Y').'-'.str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
                'status' => $status,
                'billing_day' => rand(1, 28),
                'mrc_amount' => $pkg->price_mrc ?? 150000,
                'otc_installation_fee' => $pkg->price_otc ?? 0,
                'contract_months' => $pkg->contract_min_months,
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

            DB::table('service_subscriptions')->insert([
                ...$data,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
