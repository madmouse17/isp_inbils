<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\CustomerDemoSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoUserSeeder::class);
        $this->companyId = (int) Company::query()->where('code', 'DEMO')->value('id');
    }

    public function test_it_seeds_customers_addresses_and_subscriptions_without_a_signed_in_user(): void
    {
        $this->seed(CustomerDemoSeeder::class);

        $customers = DB::table('customers')->where('company_id', $this->companyId);
        $subscriptions = DB::table('service_subscriptions')->where('company_id', $this->companyId);

        $this->assertEquals(['Company' => 8, 'Individual' => 12], (clone $customers)->select('type', DB::raw('count(*) as total'))->groupBy('type')->pluck('total', 'type')->map(fn ($total) => (int) $total)->all());
        $this->assertSame(30, (clone $subscriptions)->count());
        $this->assertEquals(
            ['active' => 20, 'pending' => 3, 'suspended' => 5, 'terminated' => 2],
            (clone $subscriptions)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
        );
        $this->assertSame(30, (clone $subscriptions)->distinct()->count('code'), 'Subscription codes come from the number sequence and never repeat.');
        $this->assertSame(0, DB::table('service_subscriptions as s')
            ->join('customer_addresses as a', 'a.id', '=', 's.installation_address_id')
            ->where('s.company_id', $this->companyId)
            ->whereColumn('a.customer_id', '<>', 's.customer_id')
            ->count(), 'A subscription is installed at an address of another customer.');

        $customer = User::query()->where('email', 'customer@demo.inbils.test')->firstOrFail();
        $this->assertSame(['customer'], CompanyService::runAs(
            $this->companyId,
            fn () => $customer->fresh()->roles()->pluck('template_key')->all(),
        ));
    }

    public function test_it_leaves_a_company_that_has_customers_alone(): void
    {
        $this->seed(CustomerDemoSeeder::class);
        $before = [DB::table('customers')->count(), DB::table('service_subscriptions')->count()];

        $this->seed(CustomerDemoSeeder::class);

        $this->assertSame($before, [DB::table('customers')->count(), DB::table('service_subscriptions')->count()]);
    }
}
