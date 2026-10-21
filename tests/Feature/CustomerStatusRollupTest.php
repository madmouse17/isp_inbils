<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerStatusRecalculator;
use App\Services\Core\SubscriptionService;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Database\Factories\ServicePackageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class CustomerStatusRollupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin');
        $this->actingAs($this->admin);
    }

    /** @param list<string> $statuses */
    private function customerWith(array $statuses, ?Company $company = null): Customer
    {
        $company ??= $this->company;
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        foreach ($statuses as $status) {
            $this->subscription($customer, $status);
        }

        return $customer;
    }

    private function subscription(Customer $customer, string $status): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $customer->company_id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $customer->company_id, 'customer_id' => $customer->id, 'label' => Str::random(12)])->id,
            'status' => $status,
        ]);
    }

    private function statusOf(Customer $customer): string
    {
        return $customer->fresh()->customer_status;
    }

    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function matrix(): array
    {
        return [
            'no subscriptions' => [[], 'prospective'],
            'one pending' => [['pending'], 'prospective'],
            'pending + active' => [['pending', 'active'], 'active'],
            'active + suspended' => [['active', 'suspended'], 'active'],
            'suspended only' => [['suspended'], 'suspended'],
            'suspended + terminated' => [['suspended', 'terminated'], 'suspended'],
            'all terminated' => [['terminated', 'terminated'], 'terminated'],
            'terminated + pending' => [['terminated', 'pending'], 'inactive'],
        ];
    }

    /** @param list<string> $statuses */
    #[DataProvider('matrix')]
    public function test_rollup_rule_matrix(array $statuses, string $expected): void
    {
        $customer = $this->customerWith($statuses);

        $this->assertSame($expected, CustomerStatusRecalculator::recalculate($customer->id));
        $this->assertSame($expected, $this->statusOf($customer));
    }

    public function test_soft_deleted_subscription_is_ignored(): void
    {
        $customer = $this->customerWith(['pending']);
        $this->subscription($customer, 'active')->delete();

        $this->assertSame('prospective', CustomerStatusRecalculator::recalculate($customer->id));
    }

    public function test_new_customer_defaults_to_prospective(): void
    {
        $this->assertSame('prospective', $this->statusOf(Customer::factory()->create(['company_id' => $this->company->id])));
    }

    public function test_subscription_transitions_roll_up_to_the_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $this->company->id]);
        $address = CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);
        $input = ['customer_id' => $customer->id, 'service_package_id' => $package->id, 'installation_address_id' => $address->id, 'billing_day' => 5];

        $first = SubscriptionService::create($input);
        $this->assertSame('prospective', $this->statusOf($customer));

        SubscriptionService::activate($first);
        $this->assertSame('active', $this->statusOf($customer));

        SubscriptionService::suspend($first, 'late');
        $this->assertSame('suspended', $this->statusOf($customer));

        SubscriptionService::reactivate($first);
        $this->assertSame('active', $this->statusOf($customer));

        $second = SubscriptionService::create($input);
        SubscriptionService::terminate($first, 'moved');
        $this->assertSame('inactive', $this->statusOf($customer), 'terminated + pending is inactive');

        SubscriptionService::terminate($second, 'moved');
        $this->assertSame('terminated', $this->statusOf($customer));
    }

    public function test_http_routes_roll_up_to_the_customer(): void
    {
        $customer = $this->customerWith(['pending']);
        $subscription = $customer->subscriptions()->firstOrFail();

        $this->post(route('admin.subscriptions.activate', $subscription))->assertRedirect();
        $this->assertSame('active', $this->statusOf($customer));

        $this->post(route('admin.subscriptions.suspend', $subscription), ['reason' => 'late payment'])->assertRedirect();
        $this->assertSame('suspended', $this->statusOf($customer));

        $this->post(route('admin.subscriptions.reactivate', $subscription))->assertRedirect();
        $this->assertSame('active', $this->statusOf($customer));

        $this->post(route('admin.subscriptions.terminate', $subscription), ['reason' => 'moved'])->assertRedirect();
        $this->assertSame('terminated', $this->statusOf($customer));
    }

    public function test_customer_update_and_store_ignore_customer_status(): void
    {
        $customer = $this->customerWith(['pending']);

        $this->put(route('admin.customers.update', $customer), [
            'code' => $customer->code,
            'name' => 'Renamed',
            'type' => 'Individual',
            'is_active' => true,
            OptimisticLock::FIELD => OptimisticLock::version($customer->fresh()),
            'customer_status' => 'terminated',
        ])->assertRedirect();

        $this->assertSame('Renamed', $customer->fresh()->name);
        $this->assertSame('prospective', $this->statusOf($customer));
    }

    public function test_audit_row_only_when_status_changes(): void
    {
        $customer = $this->customerWith(['active']);
        $audits = fn () => Activity::query()->where('log_name', 'customer')->where('description', 'status_changed')->get();

        CustomerStatusRecalculator::recalculate($customer->id);
        $this->assertCount(1, $audits());
        $this->assertEquals(['from' => 'prospective', 'to' => 'active'], $audits()->first()->properties->only(['from', 'to'])->all());

        CustomerStatusRecalculator::recalculate($customer->id);
        $this->assertCount(1, $audits());
    }

    public function test_index_filters_and_sorts_by_customer_status(): void
    {
        $suspended = $this->customerWith(['suspended']);
        $active = $this->customerWith(['active']);
        $this->customerWith(['pending']);
        $foreign = $this->customerWith(['suspended'], Company::factory()->create(['is_active' => true]));
        foreach ([$suspended, $active, $foreign] as $customer) {
            CompanyService::runAs($customer->company_id, fn () => CustomerStatusRecalculator::recalculate($customer->id));
        }

        $this->get(route('admin.customers.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $suspended->public_id)
                ->where('customers.data.0.customer_status', 'suspended'));

        $this->get(route('admin.customers.index', ['sort' => 'customer_status', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.customer_status', 'prospective')
                ->where('customers.data.2.customer_status', 'suspended'));
    }

    public function test_account_filter_keeps_is_active_semantics(): void
    {
        Customer::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $off = Customer::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);

        $this->get(route('admin.customers.index', ['account' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $off->public_id));
    }

    public function test_command_dry_run_writes_nothing_and_real_run_is_idempotent_and_tenant_scoped(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $mine = $this->customerWith(['active']);
        $theirs = $this->customerWith(['active'], $other);

        $this->artisan('customers:recalculate-status', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('Would change 1 customer(s).')
            ->assertSuccessful();
        $this->assertSame('prospective', $this->statusOf($mine));

        $this->artisan('customers:recalculate-status', ['--company' => $this->company->id])
            ->expectsOutputToContain('Changed 1 customer(s).')
            ->assertSuccessful();
        $this->assertSame('active', $this->statusOf($mine));
        $this->assertSame('prospective', $this->statusOf($theirs), 'other company untouched');

        $this->artisan('customers:recalculate-status', ['--company' => $this->company->id])
            ->expectsOutputToContain('Changed 0 customer(s).')
            ->assertSuccessful();
    }

    public function test_customer_status_is_written_only_by_the_recalculator(): void
    {
        $offenders = collect(['app', 'Modules'])
            ->flatMap(fn (string $dir) => Finder::create()->files()->name('*.php')->in(base_path($dir)))
            ->filter(fn ($file) => $file->getRealPath() !== (new \ReflectionClass(CustomerStatusRecalculator::class))->getFileName())
            ->filter(fn ($file) => preg_match('/(forceFill|update|create|fill|insert|upsert)\(\s*\[[^\]]*[\'"]customer_status[\'"]\s*=>|->customer_status\s*=(?!=)/', $file->getContents()) === 1)
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $offenders, 'customer_status must only be written by CustomerStatusRecalculator');
    }

    public function test_every_subscription_transition_calls_the_recalculator(): void
    {
        $source = file_get_contents((new \ReflectionClass(SubscriptionService::class))->getFileName());

        $this->assertSame(5, substr_count($source, 'CustomerStatusRecalculator::recalculate('), 'create/activate/suspend/reactivate/terminate');
    }

    public function test_company_context_is_required_to_recalculate(): void
    {
        $customer = $this->customerWith(['active']);

        $this->expectException(ModelNotFoundException::class);
        CompanyService::runAs(Company::factory()->create()->id, fn () => CustomerStatusRecalculator::recalculate($customer->id));
    }

    public function test_array_valued_filter_params_are_ignored_not_500(): void
    {
        $this->customerWith(['pending']);
        $params = ['status' => ['x'], 'type' => ['x'], 'account' => ['x'], 'search' => ['x'], 'acquired_by' => ['x'], 'sort' => ['x'], 'direction' => ['x'], 'per_page' => ['x']];

        $this->get(route('admin.customers.index', $params))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
        $this->get(route('admin.customers.export', ['format' => 'csv'] + $params))->assertOk();
    }
}
