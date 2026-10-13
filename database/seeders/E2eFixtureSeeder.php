<?php

namespace Database\Seeders;

use App\Actions\SetCustomerWhatsAppConsentAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Services\Core\LocationService;
use App\Services\WhatsApp\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\Ipam\Services\VlanService;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;
use Modules\NetworkAsset\Services\Radius\Realm;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class E2eFixtureSeeder extends Seeder
{
    private const DATATABLE_CUSTOMERS = [
        'Atlas Fiber',
        'Aria Fiber',
        'Astra Fiber',
        'Aurora Fiber',
        'Apex Fiber',
        'Ariel Fiber',
        'Aster Fiber',
        'Alto Fiber',
        'Amber Fiber',
        'Axiom Fiber',
        'Bima Fiber',
    ];

    private const SUBSIDIARY_CODE = 'E2E-SUB';

    private const SUBSIDIARY_NAME = 'E2E Subsidiary Company';

    public function run(): void
    {
        $this->seedIndonesiaRegions();

        $company = CompanyService::current() ?? Company::query()->first();

        if (! $company) {
            return;
        }

        // A seeder has no user: it names the company it seeds, so every scoped query and write acts in it.
        CompanyService::runAs((int) $company->id, fn () => $this->seedCompany($company));
        $this->seedSubsidiary($company);
    }

    /**
     * The group of the Head company specs: an active subsidiary under the demo company, in its
     * currency so the consolidated report adds up, and the demo admin as a member of both.
     */
    private function seedSubsidiary(Company $head): void
    {
        $subsidiary = Company::query()->where('code', self::SUBSIDIARY_CODE)->first()
            ?? app(CompanyHierarchyService::class)->createSubsidiary($head, [
                'name' => self::SUBSIDIARY_NAME,
                'code' => self::SUBSIDIARY_CODE,
            ]);
        $admin = User::query()->where('email', 'admin@demo.inbils.test')->first();

        if ($admin) {
            app(CompanyMembershipService::class)->grant($admin, $subsidiary);
        }

        CompanyService::runAs((int) $subsidiary->id, fn () => $this->seedSubsidiaryFigures($subsidiary, $admin));
    }

    /**
     * What the subsidiary reports: one customer with an active subscription (MRR 75000) and one sent
     * invoice (outstanding 250000). No other spec writes here, so these figures stay exact.
     */
    private function seedSubsidiaryFigures(Company $subsidiary, ?User $admin): void
    {
        $now = now();
        $customer = Customer::query()->updateOrCreate(
            ['company_id' => $subsidiary->id, 'code' => 'E2E-SUB-CUST'],
            [
                'name' => 'E2E Subsidiary Customer',
                'type' => 'Individual',
                'email' => 'e2e-subsidiary-customer@example.test',
                'phone' => '0800009001',
                'is_active' => true,
            ],
        );

        if ($admin) {
            DB::table('invoices')->updateOrInsert(
                ['company_id' => $subsidiary->id, 'number' => 'E2E-SUB-INV'],
                [
                    'type' => 'one_time',
                    'source' => 'manual',
                    'customer_id' => $customer->id,
                    'subscription_id' => null,
                    'work_order_id' => null,
                    'issue_date' => $now->copy()->subDays(10)->toDateString(),
                    'due_date' => $now->copy()->addDays(4)->toDateString(),
                    'billing_period_start' => null,
                    'billing_period_end' => null,
                    'status' => 'sent',
                    'subtotal' => 250000,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => 250000,
                    'paid_amount' => 0,
                    'notes' => null,
                    'created_by' => $admin->id,
                    'sent_at' => $now,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $packageId = DB::table('service_packages')->where('company_id', $subsidiary->id)->value('id');
        $location = Location::query()->where('company_id', $subsidiary->id)->where('type', 'pop')->first();

        if (! $packageId || ! $location) {
            return;
        }

        $address = CustomerAddress::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'label' => 'E2E Install'],
            [
                'company_id' => $subsidiary->id,
                'address' => 'E2E subsidiary street',
                'city' => 'Bandung',
                'postal_code' => '40111',
                'lat' => '-6.9175000',
                'lng' => '107.6191000',
                'is_primary' => true,
            ],
        );

        ServiceSubscription::query()->updateOrCreate(
            ['company_id' => $subsidiary->id, 'code' => 'E2E-SUB-SUBSCRIPTION'],
            [
                'customer_id' => $customer->id,
                'service_package_id' => $packageId,
                'installation_address_id' => $address->id,
                'status' => 'active',
                'activation_date' => $now->copy()->subDay()->toDateString(),
                'billing_day' => 1,
                'next_invoice_date' => $now->copy()->addMonth()->toDateString(),
                'serving_pop_id' => $location->id,
                'mrc_amount' => 75000,
                'otc_installation_fee' => 0,
                'contract_months' => 12,
            ],
        );
    }

    private function seedCompany(Company $company): void
    {
        $now = now();

        $this->seedInventoryTableFixtures($company);

        DB::table('units')->updateOrInsert(
            ['company_id' => $company->id, 'name' => 'E2E Unit'],
            ['symbol' => 'e2e', 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
        );
        $unitId = DB::table('units')
            ->where('company_id', $company->id)
            ->where('name', 'E2E Unit')
            ->value('id');

        $categoryId = DB::table('categories')->where('company_id', $company->id)->value('id');

        if (! $categoryId) {
            $categoryId = DB::table('categories')->insertGetId([
                'company_id' => $company->id,
                'parent_id' => null,
                'unit_id' => $unitId,
                'name' => 'E2E Category',
                'code' => 'E2E-CAT',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('categories')
                ->where('id', $categoryId)
                ->whereNull('unit_id')
                ->update(['unit_id' => $unitId, 'updated_at' => $now]);
        }

        if ($categoryId) {
            $categoryUnitId = DB::table('categories')->where('id', $categoryId)->value('unit_id') ?: $unitId;
            DB::table('products')->updateOrInsert(
                ['company_id' => $company->id, 'sku' => 'E2E-STOCK-PRODUCT'],
                [
                    'category_id' => $categoryId,
                    'unit_id' => $categoryUnitId,
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

            $serializedProductId = DB::table('products')->updateOrInsert(
                ['company_id' => $company->id, 'sku' => 'E2E-SERIALIZED-PRODUCT'],
                [
                    'category_id' => $categoryId,
                    'unit_id' => $categoryUnitId,
                    'name' => 'E2E Serialized ONT Product',
                    'type' => 'asset',
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
            $serializedProductId = DB::table('products')
                ->where('company_id', $company->id)
                ->where('sku', 'E2E-SERIALIZED-PRODUCT')
                ->value('id');
        }
        $this->seedStockCardReportMovements($company);

        $customer = Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-LINKED-CUST'],
            [
                'name' => 'E2E Linked Customer',
                'type' => 'Individual',
                'email' => 'e2e-linked-customer@example.test',
                'phone' => '0800005195',
                'is_active' => true,
                // The demo customer user owns this customer: portal specs (tickets, private media) log in as its owner.
                'user_id' => User::query()->where('email', 'customer@demo.inbils.test')->value('id'),
            ],
        );

        foreach (self::DATATABLE_CUSTOMERS as $index => $name) {
            Customer::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => 'E2E-DT-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)],
                [
                    'name' => $name,
                    'type' => 'Individual',
                    'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
                    'phone' => '0800'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ],
            );
        }
        $this->seedReceivablesFixtures($company, $now);

        $address = CustomerAddress::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'label' => 'E2E Install'],
            [
                'company_id' => $company->id,
                'address' => 'E2E harness street',
                'city' => 'Jakarta',
                'postal_code' => '12345',
                'lat' => '-6.2000000',
                'lng' => '106.8166667',
                'is_primary' => true,
            ],
        );

        $packageId = DB::table('service_packages')->where('company_id', $company->id)->value('id');
        $location = Location::query()->where('company_id', $company->id)->where('type', 'pop')->first()
            ?? Location::query()->where('company_id', $company->id)->first();

        if (! $packageId || ! $location) {
            return;
        }

        // Pasangan produk serialized + asset available yang valid untuk journey
        // SPK add-item (kontrak StoreWorkOrderItemRequest: asset wajib & cocok produk).
        $serializedProductId = $serializedProductId
            ?? DB::table('products')
                ->where('company_id', $company->id)
                ->where('sku', 'E2E-SERIALIZED-PRODUCT')
                ->value('id');
        if ($serializedProductId) {
            NetworkAsset::query()->updateOrCreate(
                ['company_id' => $company->id, 'serial_number' => 'E2E-SERIALIZED-ASSET-001'],
                [
                    'code' => 'E2E-SERIALIZED-ASSET',
                    'name' => 'E2E Serialized ONT',
                    'asset_type' => 'onu_ont',
                    'location_id' => $location->id,
                    'product_id' => $serializedProductId,
                    'customer_id' => null,
                    'subscription_id' => null,
                    'status' => 'available',
                    'ownership' => 'owned',
                    'vendor' => 'E2E',
                    'model' => 'Harness',
                ],
            );
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

        $adminUser = User::query()->where('email', 'admin@demo.inbils.test')->first();
        $this->seedDispatchFixtures($company, $customer->id, $adminUser?->id, $now);
        $this->seedOperationalTableFixtures($company, $customer->id, $location->id, $adminUser?->id);
        $this->seedSalesAttributionFixtures($company);
        $this->seedNetworkLaneFixtures($location, (int) $packageId, $adminUser);
        $this->seedSalesKpiFixtures($company);
        if ($adminUser && Schema::hasTable('activity_log')) {
            $prev = Auth::user();
            Auth::setUser($adminUser);
            try {
                AuditService::log(
                    module: 'customer',
                    action: 'created',
                    properties: [
                        'attributes' => [
                            'name' => $customer->name,
                            'status' => 'active',
                        ],
                        'old' => [],
                    ],
                    subject: $customer,
                    companyId: $company->id
                );
            } finally {
                if ($prev) {
                    Auth::setUser($prev);
                } else {
                    Auth::forgetGuards();
                }
            }
        }
    }

    private function seedReceivablesFixtures(Company $company, Carbon $now): void
    {
        $adminId = User::query()->where('email', 'admin@demo.inbils.test')->value('id');

        if (! $adminId) {
            return;
        }

        foreach (self::DATATABLE_CUSTOMERS as $index => $name) {
            $customerId = Customer::query()
                ->where('company_id', $company->id)
                ->where('code', 'E2E-DT-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT))
                ->value('id');

            if (! $customerId) {
                continue;
            }

            $amount = 10000 * ($index + 1);
            $dueDate = match ($index % 5) {
                0 => $now->toDateString(),
                1 => $now->copy()->subDays(15)->toDateString(),
                2 => $now->copy()->subDays(45)->toDateString(),
                3 => $now->copy()->subDays(75)->toDateString(),
                default => $now->copy()->subDays(105)->toDateString(),
            };

            DB::table('invoices')->updateOrInsert(
                ['company_id' => $company->id, 'number' => 'E2E-REC-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)],
                [
                    'type' => 'one_time',
                    'source' => 'manual',
                    'customer_id' => $customerId,
                    'subscription_id' => null,
                    'work_order_id' => null,
                    'issue_date' => $now->copy()->subDays(120)->toDateString(),
                    'due_date' => $dueDate,
                    'billing_period_start' => null,
                    'billing_period_end' => null,
                    'status' => 'sent',
                    'subtotal' => $amount,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => $amount,
                    'paid_amount' => 0,
                    'notes' => null,
                    'created_by' => $adminId,
                    'sent_at' => $now,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $linkedCustomerId = Customer::query()
            ->where('company_id', $company->id)
            ->where('code', 'E2E-LINKED-CUST')
            ->value('id');

        if ($linkedCustomerId) {
            DB::table('invoices')->updateOrInsert(
                ['company_id' => $company->id, 'number' => 'E2E-REC-LINKED'],
                [
                    'type' => 'one_time',
                    'source' => 'manual',
                    'customer_id' => $linkedCustomerId,
                    'subscription_id' => null,
                    'work_order_id' => null,
                    'issue_date' => $now->copy()->subDays(120)->toDateString(),
                    'due_date' => $now->copy()->subDays(15)->toDateString(),
                    'billing_period_start' => null,
                    'billing_period_end' => null,
                    'status' => 'sent',
                    'subtotal' => 120000,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => 120000,
                    'paid_amount' => 0,
                    'notes' => null,
                    'created_by' => $adminId,
                    'sent_at' => $now,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function seedInventoryTableFixtures(Company $company): void
    {
        $actorId = User::query()->where('company_id', $company->id)
            ->where('email', 'admin@demo.inbils.test')->value('id');

        if (! $actorId || ! Schema::hasTable('material_return_cases')) {
            return;
        }

        DB::transaction(function () use ($company, $actorId) {
            $warehouse = Location::query()->firstOrCreate(
                ['company_id' => $company->id, 'code' => 'DTC-WAREHOUSE'],
                ['name' => 'DTC Warehouse', 'type' => 'pop', 'is_active' => true],
            );
            $categoryId = null;
            $unitId = null;

            for ($number = 1; $number <= 11; $number++) {
                $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $createdAt = Carbon::parse('2026-01-01 09:00:00')->addDays($number - 1);
                $timestamps = ['created_at' => $createdAt, 'updated_at' => $createdAt];
                DB::table('units')->updateOrInsert(
                    ['company_id' => $company->id, 'name' => 'DTC Unit '.$suffix],
                    ['symbol' => 'DTC-U'.$suffix, 'deleted_at' => null] + $timestamps,
                );
                $rowUnitId = DB::table('units')->where('company_id', $company->id)
                    ->where('name', 'DTC Unit '.$suffix)->value('id');
                DB::table('categories')->updateOrInsert(
                    ['company_id' => $company->id, 'code' => 'DTC-CAT-'.$suffix],
                    ['name' => 'DTC Category '.$suffix, 'unit_id' => $rowUnitId, 'is_active' => true, 'deleted_at' => null] + $timestamps,
                );
                $categoryId ??= DB::table('categories')->where('company_id', $company->id)
                    ->where('code', 'DTC-CAT-'.$suffix)->value('id');
                $unitId ??= $rowUnitId;
                DB::table('products')->updateOrInsert(
                    ['company_id' => $company->id, 'sku' => 'DTC-P'.$suffix],
                    [
                        'category_id' => $categoryId, 'unit_id' => $unitId,
                        'name' => 'DTC Product '.$suffix, 'type' => 'consumable',
                        'track_stock' => true, 'sell_price' => $number * 100,
                        'cost_price' => $number * 50, 'min_stock' => 20,
                        'is_active' => true, 'deleted_at' => null,
                    ] + $timestamps,
                );
                $productId = DB::table('products')->where('company_id', $company->id)
                    ->where('sku', 'DTC-P'.$suffix)->value('id');

                if (DB::table('inventory_requests')->where('company_id', $company->id)
                    ->where('code', 'DTC-RET-'.$suffix)->exists()) {
                    continue;
                }

                $draftId = DB::table('inventory_requests')->insertGetId([
                    'company_id' => $company->id, 'code' => 'DTC-REQ-'.$suffix,
                    'requested_by' => $actorId, 'warehouse_location_id' => $warehouse->id,
                    'status' => 'draft', 'needed_at' => $createdAt->copy()->addMonth(),
                    'reason' => 'DTC request '.$suffix,
                ] + $timestamps);
                DB::table('inventory_request_items')->insert([
                    'company_id' => $company->id, 'inventory_request_id' => $draftId,
                    'product_id' => $productId, 'source_location_id' => $warehouse->id,
                    'quantity_requested' => $number, 'quantity_approved' => 0, 'quantity_issued' => 0,
                ] + $timestamps);
                $issuedId = DB::table('inventory_requests')->insertGetId([
                    'company_id' => $company->id, 'code' => 'DTC-RET-'.$suffix,
                    'requested_by' => $actorId, 'warehouse_location_id' => $warehouse->id,
                    'status' => 'issued', 'reason' => 'DTC return source '.$suffix,
                    'approved_by' => $actorId, 'prepared_by' => $actorId,
                    'ready_by' => $actorId, 'issued_by' => $actorId,
                    'submitted_at' => $createdAt, 'approved_at' => $createdAt,
                    'prepared_at' => $createdAt, 'ready_at' => $createdAt, 'issued_at' => $createdAt,
                ] + $timestamps);
                $issuedItemId = DB::table('inventory_request_items')->insertGetId([
                    'company_id' => $company->id, 'inventory_request_id' => $issuedId,
                    'product_id' => $productId, 'source_location_id' => $warehouse->id,
                    'quantity_requested' => 1, 'quantity_approved' => 1, 'quantity_issued' => 1,
                ] + $timestamps);
                DB::table('stocks')->insert([
                    'company_id' => $company->id, 'product_id' => $productId,
                    'location_id' => $warehouse->id, 'quantity' => $number, 'reserved_quantity' => 0,
                ] + $timestamps);
                foreach ([
                    ['receive', 1, 1, 'DTC return opening '.$suffix],
                    ['issue', 1, 0, 'DTC return issue '.$suffix],
                    ['receive', $number, $number, 'DTC Movement '.$suffix],
                ] as $offset => [$type, $quantity, $balance, $note]) {
                    DB::table('stock_movements')->insert([
                        'company_id' => $company->id, 'product_id' => $productId,
                        'from_location_id' => $type === 'issue' ? $warehouse->id : null,
                        'to_location_id' => $type === 'receive' ? $warehouse->id : null,
                        'movement_type' => $type, 'quantity' => $quantity,
                        'balance_after' => $balance, 'reserved_after' => 0,
                        'reference_type' => $type === 'issue' ? 'inventory_request' : null,
                        'reference_id' => $type === 'issue' ? $issuedId : null,
                        'note' => $note, 'created_by' => $actorId,
                        'created_at' => $createdAt->copy()->addSeconds($offset),
                        'updated_at' => $createdAt->copy()->addSeconds($offset),
                    ]);
                }
                $caseId = DB::table('material_return_cases')->insertGetId([
                    'company_id' => $company->id, 'inventory_request_id' => $issuedId,
                    'warehouse_location_id' => $warehouse->id, 'technician_id' => $actorId,
                    'status' => 'awaiting_receipt', 'declaration_version' => 1,
                    'declared_by' => $actorId, 'declared_at' => $createdAt->copy()->addMinute(),
                ] + $timestamps);
                DB::table('material_return_lines')->insert([
                    'company_id' => $company->id, 'material_return_case_id' => $caseId,
                    'inventory_request_item_id' => $issuedItemId, 'product_id' => $productId,
                    'issued_qty' => 1, 'actual_used_qty' => 0, 'declared_good_return_qty' => 1,
                    'declared_discrepancy_qty' => 0, 'accepted_good_return_qty' => 0,
                    'damaged_return_qty' => 0, 'lost_qty' => 0, 'other_discrepancy_qty' => 0,
                ] + $timestamps);
                foreach ([
                    [null, 'awaiting_declaration', 'created'],
                    ['awaiting_declaration', 'awaiting_receipt', 'declared'],
                ] as $offset => [$from, $to, $action]) {
                    DB::table('material_return_events')->insert([
                        'company_id' => $company->id, 'material_return_case_id' => $caseId,
                        'from_status' => $from, 'to_status' => $to, 'action' => $action,
                        'actor_id' => $actorId, 'reason' => 'DTC return '.$suffix,
                        'created_at' => $createdAt->copy()->addMinutes($offset),
                        'updated_at' => $createdAt->copy()->addMinutes($offset),
                    ]);
                }
            }
        });
    }

    private function seedStockCardReportMovements(Company $company): void
    {
        $actorId = User::query()->where('company_id', $company->id)
            ->where('email', 'admin@demo.inbils.test')->value('id');
        $productId = DB::table('products')->where('company_id', $company->id)
            ->where('sku', 'E2E-STOCK-PRODUCT')->value('id');
        $location = Location::query()->where('company_id', $company->id)
            ->where('type', 'pop')->first();

        if (! $actorId || ! $productId || ! $location) {
            return;
        }

        for ($number = 1; $number <= 11; $number++) {
            $note = 'E2E Stock Card Movement '.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            if (DB::table('stock_movements')->where('company_id', $company->id)
                ->where('product_id', $productId)->where('note', $note)->exists()) {
                continue;
            }

            $createdAt = Carbon::parse('2026-08-01 09:00:00')->addDays($number - 1);
            DB::table('stock_movements')->insert([
                'company_id' => $company->id, 'product_id' => $productId,
                'from_location_id' => null, 'to_location_id' => $location->id,
                'movement_type' => 'receive', 'quantity' => $number,
                'balance_after' => $number, 'reserved_after' => 0,
                'reference_type' => null, 'reference_id' => null,
                'note' => $note, 'created_by' => $actorId,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }
    }

    private function seedOperationalTableFixtures(Company $company, int $customerId, int $locationId, ?int $adminId): void
    {
        if (! $adminId) {
            return;
        }

        DB::transaction(function () use ($company, $customerId, $locationId, $adminId) {
            $createdAt = Carbon::parse('2026-01-01 09:00:00');
            DB::table('ticket_categories')->updateOrInsert(
                ['company_id' => $company->id, 'code' => 'DTD-CATEGORY'],
                [
                    'name' => 'DTD Category', 'default_sla_hours' => 24,
                    'default_priority' => 'high', 'is_active' => true, 'deleted_at' => null,
                    'created_at' => $createdAt, 'updated_at' => $createdAt,
                ],
            );
            $categoryId = DB::table('ticket_categories')->where('company_id', $company->id)
                ->where('code', 'DTD-CATEGORY')->value('id');

            for ($number = 1; $number <= 11; $number++) {
                $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $date = $createdAt->copy()->addDays($number - 1);
                $timestamps = ['created_at' => $date, 'updated_at' => $date, 'deleted_at' => null];
                Ticket::withTrashed()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => 'DTD-TICKET-'.$suffix],
                    [
                        'title' => 'DTD Ticket '.$suffix, 'description' => 'DataTable D ticket fixture.',
                        'source' => 'internal', 'category_id' => $categoryId,
                        'status' => 'open', 'priority' => 'high', 'customer_id' => $customerId,
                        'assigned_to' => null, 'created_by' => $adminId,
                        'sla_deadline' => $date->copy()->addDay(),
                    ] + $timestamps,
                );
                WorkOrder::withTrashed()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => 'DTD-SPK-'.$suffix],
                    [
                        'type' => 'maintenance', 'title' => 'DTD SPK '.$suffix,
                        'description' => 'DataTable D work order fixture.', 'status' => 'generated',
                        'customer_id' => $customerId, 'assigned_to' => null, 'source' => 'manual',
                        'priority' => 'high', 'created_by' => $adminId, 'ticket_id' => null,
                        'scheduled_date' => $date->toDateString(),
                    ] + $timestamps,
                );
                NetworkAsset::withTrashed()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => 'DTD-ASSET-'.$suffix],
                    [
                        'name' => 'DTD Asset '.$suffix, 'serial_number' => 'DTD-SERIAL-'.$suffix,
                        'asset_type' => 'onu_ont', 'location_id' => $locationId,
                        'status' => 'available', 'ownership' => 'owned',
                        'customer_id' => null, 'subscription_id' => null,
                    ] + $timestamps,
                );
            }
        });
    }

    private function seedDispatchFixtures(Company $company, int $customerId, ?int $adminId, Carbon $now): void
    {
        if (! $adminId || ! Schema::hasTable('tickets') || ! Schema::hasTable('work_orders') || ! Schema::hasTable('ticket_categories')) {
            return;
        }

        $categoryId = DB::table('ticket_categories')
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->value('id');

        if (! $categoryId) {
            return;
        }

        Ticket::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-DISPATCH-TICKET'],
            [
                'title' => 'E2E Dispatch Ticket',
                'description' => 'Deterministic dispatch queue ticket.',
                'source' => 'internal',
                'category_id' => $categoryId,
                'status' => 'open',
                'priority' => 'high',
                'customer_id' => $customerId,
                'assigned_to' => null,
                'created_by' => $adminId,
                'sla_deadline' => $now->copy()->addDay(),
                'deleted_at' => null,
            ],
        );

        WorkOrder::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-DISPATCH-SPK'],
            [
                'type' => 'maintenance',
                'title' => 'E2E Dispatch SPK',
                'description' => 'Deterministic dispatch queue SPK.',
                'status' => 'generated',
                'customer_id' => $customerId,
                'assigned_to' => null,
                'source' => 'manual',
                'priority' => 'high',
                'created_by' => $adminId,
                'ticket_id' => null,
                'deleted_at' => null,
            ],
        );
    }

    /**
     * Seed subset wilayah Indonesia yang dipakai alur E2E edit customer
     * (cascade provinsi -> kota -> kecamatan -> kelurahan).
     * Nama mengikuti data resmi laravolt/indonesia (BPS).
     */
    private function seedIndonesiaRegions(): void
    {
        $now = now();

        // Coordinates as in the laravolt CSV; the default-map specs pick Surakarta, the demo company sits on Bandung.
        $provinces = [
            '31' => ['DAERAH KHUSUS IBUKOTA JAKARTA', -6.199048542607956, 106.83437824682376],
            '32' => ['JAWA BARAT', -6.920631357785788, 107.60331918557674],
            '33' => ['JAWA TENGAH', -7.259392265876053, 110.2014881759208],
        ];
        $cities = [
            '3171' => ['31', 'KOTA ADMINISTRASI JAKARTA PUSAT', -6.181200074228588, 106.83512454642566],
            '3174' => ['31', 'KOTA ADMINISTRASI JAKARTA SELATAN', -6.2726047320060445, 106.81003987805606],
            '3273' => ['32', 'KOTA BANDUNG', -6.919241381150077, 107.6366006270422],
            '3372' => ['33', 'KOTA SURAKARTA', -7.557893978396063, 110.82314485261672],
        ];

        foreach ($provinces as $code => [$name, $lat, $long]) {
            DB::table('indonesia_provinces')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'meta' => json_encode(['lat' => $lat, 'long' => $long]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($cities as $code => [$provinceCode, $name, $lat, $long]) {
            DB::table('indonesia_cities')->insertOrIgnore([
                'code' => $code,
                'province_code' => $provinceCode,
                'name' => $name,
                'meta' => json_encode(['lat' => $lat, 'long' => $long]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('indonesia_districts')->insertOrIgnore([
            'code' => '317106',
            'city_code' => '3171',
            'name' => 'MENTENG',
            'meta' => json_encode(['lat' => -6.19583005252484, 'long' => 106.83529809326643]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('indonesia_villages')->insertOrIgnore([
            'code' => '3171061001',
            'district_code' => '317106',
            'name' => 'MENTENG',
            'meta' => json_encode(['lat' => -6.200896699778712, 'long' => 106.83392059173829]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Dedicated customers for tests/e2e/customer-sales-attribution.spec.ts.
     * `acquired_by_user_id` is not mass assignable, so it is written directly.
     */
    private function seedSalesAttributionFixtures(Company $company): void
    {
        $acquirers = [
            'manager' => User::query()->where('email', 'manager@demo.inbils.test')->value('id'),
            'staff' => User::query()->where('email', 'staff@demo.inbils.test')->value('id'),
        ];

        $fixtures = [
            ['E2E-SALES-ASSIGN', 'E2E Sales Assign Target', null],
            ['E2E-SALES-CORRECT', 'E2E Sales Correction Target', 'staff'],
            ['E2E-SALES-STAFF-ASSIGN', 'E2E Sales Staff Assign Target', null],
            ['E2E-SALES-FILTER-MGR', 'E2E Sales Filter Manager', 'manager'],
            ['E2E-SALES-FILTER-STAFF', 'E2E Sales Filter Staff', 'staff'],
            ['E2E-SALES-FILTER-NONE', 'E2E Sales Filter None', null],
        ];

        foreach ($fixtures as $index => [$code, $name, $acquirer]) {
            $customer = Customer::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => 'Individual',
                    'email' => strtolower($code).'@example.test',
                    'phone' => '0800006'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ],
            );

            DB::table('customers')->where('id', $customer->id)->update([
                'acquired_by_user_id' => $acquirer ? $acquirers[$acquirer] : null,
            ]);
        }
    }

    /**
     * Fixed February 2025 history for tests/e2e/reporting-sales-kpi.spec.ts (period 2025-02-01..2025-02-28).
     * Everything is written with explicit dates so later E2E activity (created "now" or 120 days ago)
     * never lands in the period. No fixture invoice is an open receivable (only paid, draft, cancelled),
     * no fixture subscription is active, and no user, role or company is created.
     * Customers use updateOrInsert on (company_id, code): `acquired_by_user_id` is not mass assignable
     * and the soft-deleted customer must stay findable, so the query builder is used throughout.
     *
     * Customers (code: name, acquirer, created_at in UTC, deleted). The demo company is Asia/Jakarta and
     * reports read the period as company-local days, so the edges are 17:00 UTC of the day before:
     *   E2E-KPI-M1   E2E KPI Manager First    Demo Manager  2025-01-31 17:00:00  (first second of 1 Feb WIB: in)
     *   E2E-KPI-M2   E2E KPI Manager Last     Demo Manager  2025-02-28 16:59:59  (last second of 28 Feb WIB: in)
     *   E2E-KPI-M3   E2E KPI Manager Before   Demo Manager  2025-01-31 16:59:59  (just before: out)
     *   E2E-KPI-M4   E2E KPI Manager After    Demo Manager  2025-02-28 17:00:00  (just after: out)
     *   E2E-KPI-MDEL E2E KPI Manager Deleted  Demo Manager  2025-02-10 12:00:00  soft-deleted 2025-02-20 (history counts, customer does not)
     *   E2E-KPI-S1   E2E KPI Staff Only       Demo Staff    2025-02-15 09:00:00
     *   E2E-KPI-U1   E2E KPI Unassigned Only  none          2025-02-12 10:00:00
     *
     * Subscriptions (all terminated, so no active count or MRR changes; mrc in IDR):
     *   E2E-KPI-SUB-M1  M1    activation 2025-02-01  in
     *   E2E-KPI-SUB-MDEL MDEL activation 2025-02-28  in (soft-deleted customer still counts)
     *   E2E-KPI-SUB-M3  M3    activation 2025-01-31  out
     *   E2E-KPI-SUB-M4  M4    activation 2025-03-01  out
     *   E2E-KPI-SUB-MSOFT M1  activation 2025-02-05  soft-deleted subscription: excluded
     *   E2E-KPI-SUB-S1  S1    activation 2025-02-16  in
     *   E2E-KPI-SUB-U1  U1    activation 2025-02-17  in
     *
     * Invoices (number: customer, status, issue_date, total, payments [amount @ paid_at]):
     *   E2E-KPI-INV-M1     M1    paid       2025-02-01  1000000  [600000 @ 2025-01-31 17:00:00 UTC, 400000 @ 2025-02-28 16:59:59 UTC]  invoiced, collected 1000000
     *   E2E-KPI-INV-M2     M2    paid       2025-02-28   500000  [500000 @ 2025-02-28 17:00:00 UTC]  invoiced, payment after period
     *   E2E-KPI-INV-M3     M3    paid       2025-01-31   300000  [300000 @ 2025-02-02 10:00:00]  collected only (issued before period)
     *   E2E-KPI-INV-M4     M4    paid       2025-03-01   250000  [250000 @ 2025-03-01 08:00:00]  neither
     *   E2E-KPI-INV-MDEL   MDEL  paid       2025-02-10   800000  [800000 @ 2025-02-12 09:00:00]  invoiced + collected (soft-deleted customer)
     *   E2E-KPI-INV-MREV   M1    paid       2025-02-05   200000  [200000 @ 2025-02-06 09:00:00, 200000 @ 2025-02-07 09:00:00 REVERSED 2025-02-08]  reversed row ignored, paid_amount 200000
     *   E2E-KPI-INV-MDRAFT M1    draft      2025-02-06   999000  excluded
     *   E2E-KPI-INV-MCANC  M1    cancelled  2025-02-07   888000  excluded
     *   E2E-KPI-INV-MSOFT  M1    paid       2025-02-08   777000  [777000 @ 2025-02-09 09:00:00] soft-deleted invoice: excluded with its payment
     *   E2E-KPI-INV-S1     S1    paid       2025-02-15   400000  [400000 @ 2025-02-15 12:00:00]
     *   E2E-KPI-INV-S2     S1    paid       2025-02-20   200000  [200000 @ 2025-03-05 09:00:00]  invoiced, payment after period
     *   E2E-KPI-INV-U1     U1    paid       2025-02-12   300000  [300000 @ 2025-02-13 09:00:00]
     *
     * Expected figures for 2025-02-01..2025-02-28 (customers_acquired, subscriptions_activated, invoiced, collected, collection_rate):
     *   Demo Manager: 2, 2, 2500000 (1000000+500000+800000+200000), 2300000 (1000000+300000+800000+200000), 92.00
     *   Demo Staff:   1, 1,  600000 (400000+200000),                  400000,                                66.67
     *   Unassigned:   1, 1,  300000,                                  300000,                                100.00
     *   Total:        4, 4, 3400000,                                 3000000,                                88.24  (3000000/3400000 = 88.2352...)
     * Single-day edges (Manager / Staff), all other days of the year behave as "out":
     *   2025-02-01..2025-02-01: Manager 1 (M1), 1 (SUB-M1), 1000000 (INV-M1), 600000 (INV-M1 first payment), 60.00; Staff 0, 0, 0, 0, n/a
     *   2025-02-28..2025-02-28: Manager 1 (M2), 1 (SUB-MDEL), 500000 (INV-M2), 400000 (INV-M1 second payment @ 16:59:59 UTC), 80.00; Staff 0, 0, 0, 0, n/a
     * March 2025 (2025-03-01..2025-03-31), Manager / Staff:
     *   Manager 1 (M4), 1 (SUB-M4), 250000 (INV-M4), 750000 (500000 INV-M2 + 250000 INV-M4), 300.00
     *   Staff   0, 0, 0, 200000 (INV-S2 payment), n/a
     * All-time customers/subscriptions/money differ (the "out" rows count), the E2E spec only asserts the periods above.
     */
    private function seedSalesKpiFixtures(Company $company): void
    {
        $actorId = User::query()->where('email', 'admin@demo.inbils.test')->value('id');
        $addressId = DB::table('customer_addresses')
            ->where('company_id', $company->id)
            ->where('label', 'E2E Install')
            ->whereIn('customer_id', DB::table('customers')->where('company_id', $company->id)->where('code', 'E2E-LINKED-CUST')->select('id'))
            ->value('id');
        $packageId = DB::table('service_packages')->where('company_id', $company->id)->value('id');
        $locationId = Location::query()->where('company_id', $company->id)->where('type', 'pop')->value('id')
            ?? Location::query()->where('company_id', $company->id)->value('id');

        if (! $actorId || ! $addressId || ! $packageId || ! $locationId) {
            return;
        }

        $acquirers = [
            'manager' => User::query()->where('email', 'manager@demo.inbils.test')->value('id'),
            'staff' => User::query()->where('email', 'staff@demo.inbils.test')->value('id'),
            'none' => null,
        ];

        $customers = [
            ['E2E-KPI-M1', 'E2E KPI Manager First', 'manager', '2025-01-31 17:00:00', null],
            ['E2E-KPI-M2', 'E2E KPI Manager Last', 'manager', '2025-02-28 16:59:59', null],
            ['E2E-KPI-M3', 'E2E KPI Manager Before', 'manager', '2025-01-31 16:59:59', null],
            ['E2E-KPI-M4', 'E2E KPI Manager After', 'manager', '2025-02-28 17:00:00', null],
            ['E2E-KPI-MDEL', 'E2E KPI Manager Deleted', 'manager', '2025-02-10 12:00:00', '2025-02-20 12:00:00'],
            ['E2E-KPI-S1', 'E2E KPI Staff Only', 'staff', '2025-02-15 09:00:00', null],
            ['E2E-KPI-U1', 'E2E KPI Unassigned Only', 'none', '2025-02-12 10:00:00', null],
        ];

        $customerIds = [];
        foreach ($customers as $index => [$code, $name, $acquirer, $createdAt, $deletedAt]) {
            DB::table('customers')->updateOrInsert(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => 'Individual',
                    'email' => strtolower($code).'@example.test',
                    'phone' => '0800007'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'is_active' => true,
                    'acquired_by_user_id' => $acquirers[$acquirer],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'deleted_at' => $deletedAt,
                ],
            );
            $customerIds[substr($code, 8)] = DB::table('customers')
                ->where('company_id', $company->id)
                ->where('code', $code)
                ->value('id');
        }

        // [suffix, customer, status, activation_date, terminated_at, mrc_amount, deleted_at]
        $subscriptions = [
            ['M1', 'M1', 'terminated', '2025-02-01', '2025-02-20 10:00:00', 200000, null],
            ['MDEL', 'MDEL', 'terminated', '2025-02-28', '2025-02-28 18:00:00', 150000, null],
            ['M3', 'M3', 'terminated', '2025-01-31', '2025-02-10 10:00:00', 120000, null],
            ['M4', 'M4', 'terminated', '2025-03-01', '2025-03-10 10:00:00', 110000, null],
            ['MSOFT', 'M1', 'terminated', '2025-02-05', '2025-02-06 10:00:00', 130000, '2025-02-07 10:00:00'],
            ['S1', 'S1', 'terminated', '2025-02-16', '2025-02-25 10:00:00', 100000, null],
            ['U1', 'U1', 'terminated', '2025-02-17', '2025-02-26 10:00:00', 90000, null],
        ];

        foreach ($subscriptions as [$suffix, $customerKey, $status, $activation, $terminatedAt, $mrc, $deletedAt]) {
            DB::table('service_subscriptions')->updateOrInsert(
                ['company_id' => $company->id, 'code' => 'E2E-KPI-SUB-'.$suffix],
                [
                    'customer_id' => $customerIds[$customerKey],
                    'service_package_id' => $packageId,
                    'installation_address_id' => $addressId,
                    'status' => $status,
                    'activation_date' => $activation,
                    'billing_day' => 1,
                    'next_invoice_date' => null,
                    'serving_pop_id' => $locationId,
                    'mrc_amount' => $mrc,
                    'otc_installation_fee' => 0,
                    'contract_months' => 12,
                    'terminated_at' => $terminatedAt,
                    'terminated_reason' => 'E2E KPI fixture',
                    'created_at' => $activation.' 08:00:00',
                    'updated_at' => $terminatedAt,
                    'deleted_at' => $deletedAt,
                ],
            );
        }

        // [suffix, customer, status, issue_date, total, deleted_at, payments: [[amount, paid_at, reversed_at|null]]]
        $invoices = [
            ['M1', 'M1', 'paid', '2025-02-01', 1000000, null, [[600000, '2025-01-31 17:00:00', null], [400000, '2025-02-28 16:59:59', null]]],
            ['M2', 'M2', 'paid', '2025-02-28', 500000, null, [[500000, '2025-02-28 17:00:00', null]]],
            ['M3', 'M3', 'paid', '2025-01-31', 300000, null, [[300000, '2025-02-02 10:00:00', null]]],
            ['M4', 'M4', 'paid', '2025-03-01', 250000, null, [[250000, '2025-03-01 08:00:00', null]]],
            ['MDEL', 'MDEL', 'paid', '2025-02-10', 800000, null, [[800000, '2025-02-12 09:00:00', null]]],
            ['MREV', 'M1', 'paid', '2025-02-05', 200000, null, [[200000, '2025-02-06 09:00:00', null], [200000, '2025-02-07 09:00:00', '2025-02-08 09:00:00']]],
            ['MDRAFT', 'M1', 'draft', '2025-02-06', 999000, null, []],
            ['MCANC', 'M1', 'cancelled', '2025-02-07', 888000, null, []],
            ['MSOFT', 'M1', 'paid', '2025-02-08', 777000, '2025-02-10 09:00:00', [[777000, '2025-02-09 09:00:00', null]]],
            ['S1', 'S1', 'paid', '2025-02-15', 400000, null, [[400000, '2025-02-15 12:00:00', null]]],
            ['S2', 'S1', 'paid', '2025-02-20', 200000, null, [[200000, '2025-03-05 09:00:00', null]]],
            ['U1', 'U1', 'paid', '2025-02-12', 300000, null, [[300000, '2025-02-13 09:00:00', null]]],
        ];

        foreach ($invoices as [$suffix, $customerKey, $status, $issueDate, $total, $deletedAt, $payments]) {
            $number = 'E2E-KPI-INV-'.$suffix;
            $issuedAt = $issueDate.' 08:00:00';
            $paidAmount = (string) array_sum(array_map(
                static fn (array $payment): int => $payment[2] === null ? $payment[0] : 0,
                $payments,
            ));

            DB::table('invoices')->updateOrInsert(
                ['company_id' => $company->id, 'number' => $number],
                [
                    'type' => 'one_time',
                    'source' => 'manual',
                    'customer_id' => $customerIds[$customerKey],
                    'subscription_id' => null,
                    'work_order_id' => null,
                    'issue_date' => $issueDate,
                    'due_date' => Carbon::parse($issueDate)->addDays(14)->toDateString(),
                    'billing_period_start' => null,
                    'billing_period_end' => null,
                    'status' => $status,
                    'subtotal' => $total,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => $total,
                    'paid_amount' => $paidAmount,
                    'notes' => null,
                    'created_by' => $actorId,
                    'sent_at' => $status === 'draft' ? null : $issuedAt,
                    'cancelled_at' => $status === 'cancelled' ? $issuedAt : null,
                    'cancel_reason' => $status === 'cancelled' ? 'E2E KPI fixture' : null,
                    'cancelled_by' => $status === 'cancelled' ? $actorId : null,
                    'deleted_at' => $deletedAt,
                    'created_at' => $issuedAt,
                    'updated_at' => $issuedAt,
                ],
            );
            $invoiceId = DB::table('invoices')
                ->where('company_id', $company->id)
                ->where('number', $number)
                ->value('id');

            foreach ($payments as $position => [$amount, $paidAt, $reversedAt]) {
                DB::table('payments')->updateOrInsert(
                    ['company_id' => $company->id, 'reference' => $number.'-P'.($position + 1)],
                    [
                        'invoice_id' => $invoiceId,
                        'amount' => $amount,
                        'method' => 'transfer',
                        'paid_at' => $paidAt,
                        'received_by' => $actorId,
                        'notes' => null,
                        'cancelled_at' => $reversedAt,
                        'cancel_reason' => $reversedAt === null ? null : 'E2E KPI fixture reversal',
                        'cancelled_by' => $reversedAt === null ? null : $actorId,
                        'created_at' => $paidAt,
                        'updated_at' => $reversedAt ?? $paidAt,
                    ],
                );
            }
        }
    }

    /**
     * Fixtures of the IPAM/ODN, MikroTik and WhatsApp specs, all under the POP of E2E-LINKED-SUB: an address plan,
     * an OLT with a wired ODC and ODP (every port free), an unregistered router for the "register a NAS router"
     * flow plus a registered one (RADIUS off) with a network account on a second subscription, and a customer
     * with WhatsApp consent and a few log rows. E2E-LINKED-SUB itself gets no address, drop or network account.
     */
    private function seedNetworkLaneFixtures(Location $pop, int $packageId, ?User $admin): void
    {
        if ($admin === null) {
            return;
        }

        $previous = Auth::user();
        Auth::setUser($admin);

        try {
            $this->seedAddressPlanFixtures($pop);
            $this->seedFibreFixtures($pop);
            $this->seedRouterFixtures($pop, $packageId);
            $this->seedWhatsAppFixtures($pop, $packageId, $admin);
        } finally {
            $previous !== null ? Auth::setUser($previous) : Auth::forgetGuards();
        }
    }

    private function seedAddressPlanFixtures(Location $pop): void
    {
        $vlan = Vlan::query()->where('vid', 999)->where('location_id', $pop->id)->first()
            ?? VlanService::create(['vid' => 999, 'name' => 'E2E-VLAN', 'location_id' => $pop->id]);

        foreach ([
            ['10.99.0.0/24', 'customer_static', $vlan->id],
            ['10.99.1.0/24', 'customer_dynamic', $vlan->id],
            ['10.99.9.0/24', 'infrastructure', null],
        ] as [$cidr, $usage, $vlanId]) {
            if (! IpPrefix::query()->where('cidr', $cidr)->exists()) {
                IpPrefixService::create(['cidr' => $cidr, 'usage' => $usage, 'location_id' => $pop->id, 'vlan_id' => $vlanId, 'description' => 'E2E '.$usage]);
            }
        }
    }

    private function seedFibreFixtures(Location $pop): void
    {
        $rack = Location::query()->where('code', 'E2E-RACK-01')->first() ?? LocationService::create([
            'parent_id' => $pop->id, 'code' => 'E2E-RACK-01', 'name' => 'E2E Rack 01', 'type' => 'rack', 'is_active' => true,
        ]);
        $site = Location::query()->where('code', 'E2E-ODP-SITE-01')->first() ?? LocationService::create([
            'parent_id' => $rack->id, 'code' => 'E2E-ODP-SITE-01', 'name' => 'E2E ODP Site 01', 'type' => 'site', 'is_active' => true,
        ]);

        $olt = $this->e2eAsset('E2E-OLT-001', 'E2E OLT', 'olt', $pop->id);
        $odc = $this->e2eAsset('E2E-ODC-001', 'E2E ODC', 'odc', $rack->id);
        $odp = $this->e2eAsset('E2E-ODP-001', 'E2E ODP', 'odp', $site->id);

        if (! OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', OdnPort::KIND_PON)->exists()) {
            OdnService::createPonPorts($olt, 4);
        }

        $trunk = OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', OdnPort::KIND_PON)->where('number', 1)->firstOrFail();
        $odcSplitter = OdnSplitter::query()->where('network_asset_id', $odc->id)->first() ?? OdnService::createSplitter($odc, 4, $trunk, 'E2E ODC 1:4');

        if (! OdnSplitter::query()->where('network_asset_id', $odp->id)->exists()) {
            $feeder = OdnPort::query()->where('odn_splitter_id', $odcSplitter->id)->where('number', 1)->firstOrFail();
            OdnService::createSplitter($odp, 8, $feeder, 'E2E ODP 1:8');
        }
    }

    private function seedRouterFixtures(Location $pop, int $packageId): void
    {
        // Unregistered on purpose: the RADIUS spec registers it through the UI.
        $this->e2eAsset('E2E-ROUTER-001', 'E2E Router (unregistered)', 'router', $pop->id, '10.99.9.1');
        $registered = $this->e2eAsset('E2E-ROUTER-002', 'E2E Router (registered)', 'router', $pop->id, '10.99.9.2');

        NasDevice::query()->where('network_asset_id', $registered->id)->first() ?? NasDevice::query()->create([
            'network_asset_id' => $registered->id,
            'nasname' => '10.99.9.2',
            'radius_secret' => 'e2e-not-a-real-secret',
        ]);

        $subscription = $this->e2eWhatsAppSubscription($pop, $packageId);

        if (! NetworkAccount::query()->where('subscription_id', $subscription->id)->exists()) {
            NetworkAccount::query()->create([
                'subscription_id' => $subscription->id,
                'service_type' => 'pppoe',
                'username' => 'e2e-wa@'.Realm::forCompany((int) $subscription->company_id),
                'password' => 'e2e-pppoe-password',
            ]);
        }
    }

    private function seedWhatsAppFixtures(Location $pop, int $packageId, User $admin): void
    {
        $subscription = $this->e2eWhatsAppSubscription($pop, $packageId);
        $customer = Customer::query()->findOrFail($subscription->customer_id);

        SetCustomerWhatsAppConsentAction::execute($customer, true, $admin);

        $phone = PhoneNumber::normalizeIndonesianMobile($customer->phone) ?? throw new \LogicException('The E2E WhatsApp customer needs a valid mobile number.');

        foreach ([['sent', null], ['delivered', null], ['skipped', 'no_consent']] as $index => [$status, $reason]) {
            $at = now()->subHours(3 - $index);

            WhatsAppMessage::query()->firstOrCreate(['dedupe_key' => "e2e:wa:{$index}"], [
                'recipient_type' => 'customer',
                'recipient_id' => $customer->id,
                'event' => 'invoice_issued',
                'template' => 'tagihan_terbit',
                'language' => 'id',
                'status' => $status,
                'skip_reason' => $reason,
                'provider_message_id' => $reason === null ? "wamid.E2E{$index}" : null,
                'recipient_hash' => hash_hmac('sha256', $phone, (string) config('app.key')),
                'recipient_last4' => substr($phone, -4),
                'sent_at' => $reason === null ? $at : null,
                'delivered_at' => $status === 'delivered' ? $at->copy()->addMinute() : null,
            ]);
        }
    }

    /** The customer of the WhatsApp and router fixtures, with an active subscription of its own. */
    private function e2eWhatsAppSubscription(Location $pop, int $packageId): ServiceSubscription
    {
        $customer = Customer::query()->updateOrCreate(
            ['code' => 'E2E-WA-CUST'],
            ['name' => 'E2E WhatsApp Customer', 'type' => 'Individual', 'email' => 'e2e-wa-customer@example.test', 'phone' => '081234560001', 'is_active' => true],
        );
        $address = CustomerAddress::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'label' => 'E2E Install'],
            ['address' => 'E2E WhatsApp street', 'city' => 'Jakarta', 'postal_code' => '12345', 'lat' => '-6.2000000', 'lng' => '106.8166667', 'is_primary' => true],
        );

        return ServiceSubscription::query()->updateOrCreate(
            ['code' => 'E2E-WA-SUB'],
            [
                'customer_id' => $customer->id,
                'service_package_id' => $packageId,
                'installation_address_id' => $address->id,
                'status' => 'active',
                'activation_date' => now()->subDay()->toDateString(),
                'billing_day' => 1,
                'next_invoice_date' => now()->addMonth()->toDateString(),
                'serving_pop_id' => $pop->id,
                'mrc_amount' => 150000,
                'otc_installation_fee' => 0,
                'contract_months' => 12,
            ],
        );
    }

    private function e2eAsset(string $serial, string $name, string $type, int $locationId, ?string $managementIp = null): NetworkAsset
    {
        $asset = NetworkAsset::query()->updateOrCreate(
            ['serial_number' => $serial],
            [
                'code' => $serial,
                'name' => $name,
                'asset_type' => $type,
                'location_id' => $locationId,
                'management_ip' => $managementIp,
                'status' => 'available',
                'ownership' => 'owned',
                'vendor' => 'E2E',
                'model' => 'Harness',
            ],
        );

        IpAssignmentService::syncAssetAddresses($asset);

        return $asset;
    }
}
