<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAsset;

class E2eFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $company = CompanyService::current() ?? Company::query()->first();

        if (! $company) {
            return;
        }

        $now = now();
        $categoryId = DB::table('categories')->where('company_id', $company->id)->value('id');

        DB::table('units')->updateOrInsert(
            ['company_id' => $company->id, 'name' => 'E2E Unit'],
            ['symbol' => 'e2e', 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
        );
        $unitId = DB::table('units')
            ->where('company_id', $company->id)
            ->where('name', 'E2E Unit')
            ->value('id');

        if ($categoryId) {
            DB::table('products')->updateOrInsert(
                ['company_id' => $company->id, 'sku' => 'E2E-STOCK-PRODUCT'],
                [
                    'category_id' => $categoryId,
                    'unit_id' => $unitId,
                    'name' => 'E2E Stock Product',
                    'type' => 'consumable',
                    'track_stock' => true,
                    'sell_price' => 1,
                    'cost_price' => 1,
                    'min_stock' => 0,
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $customer = Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-LINKED-CUST'],
            [
                'name' => 'E2E Linked Customer',
                'type' => 'Individual',
                'email' => 'e2e-linked-customer@example.test',
                'phone' => '0800005195',
                'is_active' => true,
            ],
        );

        $address = CustomerAddress::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'label' => 'E2E Install'],
            [
                'company_id' => $company->id,
                'address' => 'E2E harness street',
                'city' => 'Jakarta',
                'is_installation_point' => true,
                'is_primary' => true,
            ],
        );

        $packageId = DB::table('service_packages')->where('company_id', $company->id)->value('id');
        $location = Location::query()->where('company_id', $company->id)->where('type', 'pop')->first()
            ?? Location::query()->where('company_id', $company->id)->first();

        if (! $packageId || ! $location) {
            return;
        }

        $subscription = ServiceSubscription::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-LINKED-SUB'],
            [
                'customer_id' => $customer->id,
                'service_package_id' => $packageId,
                'installation_address_id' => $address->id,
                'status' => 'active',
                'activation_date' => now()->subDay()->toDateString(),
                'billing_day' => 1,
                'next_invoice_date' => now()->addMonth()->toDateString(),
                'serving_pop_id' => $location->id,
                'mrc_amount' => 150000,
                'otc_installation_fee' => 0,
                'contract_months' => 12,
            ],
        );

        NetworkAsset::query()->updateOrCreate(
            ['company_id' => $company->id, 'serial_number' => 'E2E-LINKED-ASSET-001'],
            [
                'code' => 'E2E-LINKED-ASSET',
                'name' => 'E2E Linked ONT',
                'asset_type' => 'onu_ont',
                'location_id' => $location->id,
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'status' => 'installed',
                'ownership' => 'owned',
                'vendor' => 'E2E',
                'model' => 'Harness',
                'installed_at' => now(),
            ],
        );
    }
}
