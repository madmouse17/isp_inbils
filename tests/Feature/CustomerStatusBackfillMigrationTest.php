<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;

/**
 * MySQL DDL implicitly commits, so this test cleans up the rows it inserted itself.
 */
class CustomerStatusBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_12_400000_add_customer_status_to_customers_table.php';

    public function test_backfill_matches_the_rollup_rule_and_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $company = Company::factory()->create();
        $package = ServicePackageFactory::new()->create(['company_id' => $company->id]);
        $customerIds = $subscriptionIds = [];

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('customers', 'customer_status'));

            $cases = [
                'none' => [[], 'prospective'],
                'pending' => [['pending'], 'prospective'],
                'pending+active' => [['pending', 'active'], 'active'],
                'suspended+terminated' => [['suspended', 'terminated'], 'suspended'],
                'all terminated' => [['terminated'], 'terminated'],
                'terminated+pending' => [['terminated', 'pending'], 'inactive'],
                'deleted active ignored' => [['pending', 'active:deleted'], 'prospective'],
            ];
            $expected = [];
            $n = 0;

            foreach ($cases as $key => [$statuses, $status]) {
                $customerIds[] = $customerId = DB::table('customers')->insertGetId([
                    'company_id' => $company->id, 'code' => 'BF-'.$key, 'name' => $key, 'type' => 'Individual',
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $expected[$customerId] = $status;
                $address = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customerId, 'label' => 'BF'.$customerId]);
                foreach ($statuses as $spec) {
                    [$subStatus, $deleted] = array_pad(explode(':', $spec), 2, null);
                    $subscriptionIds[] = DB::table('service_subscriptions')->insertGetId([
                        'company_id' => $company->id, 'customer_id' => $customerId, 'service_package_id' => $package->id,
                        'installation_address_id' => $address->id,
                        'code' => 'BFS-'.++$n, 'status' => $subStatus, 'billing_day' => 1, 'mrc_amount' => 1, 'otc_installation_fee' => 0,
                        'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $migration->up();

            foreach ($expected as $customerId => $status) {
                $this->assertSame($status, DB::table('customers')->where('id', $customerId)->value('customer_status'), "customer {$customerId}");
            }
        } finally {
            if (! Schema::hasColumn('customers', 'customer_status')) {
                $migration->up();
            }
            DB::table('service_subscriptions')->whereIn('id', $subscriptionIds)->delete();
            $package->forceDelete();
            DB::table('customers')->whereIn('id', $customerIds)->delete();
        }
    }
}
