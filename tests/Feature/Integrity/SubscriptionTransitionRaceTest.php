<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerStatusRecalculator;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Service\Database\Factories\ServicePackageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Item 13 of integrity-hardening: the transitions of a customer's subscriptions queue on the customer row first (lane
 * lock order Customer, then ServiceSubscription), terminate re-checks the status after that lock, and the roll-up
 * reads the subscriptions with a locking read, so it never works from a snapshot older than the lock it holds.
 */
class SubscriptionTransitionRaceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const TERMINATE = <<<'PHP'
        config(['logging.default' => 'null']);

        App\Services\Core\SubscriptionService::terminate(
            App\Models\Core\ServiceSubscription::query()->findOrFail($args['subscription_id']),
            $args['reason'],
        );

        return ['reason' => $args['reason']];
    PHP;

    private const SUSPEND = <<<'PHP'
        config(['logging.default' => 'null']);

        App\Services\Core\SubscriptionService::suspend(
            App\Models\Core\ServiceSubscription::query()->findOrFail($args['subscription_id']),
            'unpaid',
        );

        return ['suspended' => true];
    PHP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCompanyUser();
    }

    /**
     * A customer with one subscription per status given, rolled up the way the transitions leave it.
     *
     * @param  list<string>  $statuses
     * @return array{0: Customer, 1: list<ServiceSubscription>}
     */
    private function customerWith(array $statuses): array
    {
        $customer = Customer::factory()->create(['company_id' => CompanyService::currentId()]);

        $subscriptions = array_map(fn (string $status): ServiceSubscription => ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $customer->company_id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $customer->company_id, 'customer_id' => $customer->id, 'label' => Str::random(12)])->id,
            'status' => $status,
            'activation_date' => $status === 'pending' ? null : now()->subMonths(3)->toDateString(),
        ]), $statuses);

        CustomerStatusRecalculator::recalculate($customer->id);

        return [$customer, $subscriptions];
    }

    private function statusOf(ServiceSubscription $subscription): string
    {
        return ServiceSubscription::withoutCompany()->findOrFail($subscription->id)->status;
    }

    private function audits(ServiceSubscription $subscription, string $description): int
    {
        return Activity::query()
            ->where('log_name', 'service_subscription')
            ->where('description', $description)
            ->where('subject_type', ServiceSubscription::class)
            ->where('subject_id', $subscription->id)
            ->count();
    }

    public function test_a_second_terminate_racing_the_first_is_refused(): void
    {
        [$customer, [$subscription]] = $this->customerWith(['active']);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $customer->company_id, 'subscription_id' => $subscription->id, 'reason' => 'ended by A'],
                ['company_id' => $customer->company_id, 'subscription_id' => $subscription->id, 'reason' => 'ended by B'],
            ], self::TERMINATE);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(['ok' => false, 'status' => 422, 'message' => 'Langganan sudah berhenti.'], $losers[0]);

            $row = ServiceSubscription::withoutCompany()->findOrFail($subscription->id);
            $this->assertSame('terminated', $row->status);
            $this->assertSame($winners[0]['result']['reason'], $row->terminated_reason, 'the reason of the terminate that won stands');
            $this->assertSame(1, $this->audits($subscription, 'terminated'), 'one terminate, one audit row');
        } finally {
            $this->purgeCompany($customer->company_id);
        }
    }

    public function test_terminating_from_a_copy_that_went_stale_changes_nothing(): void
    {
        [, [$subscription]] = $this->customerWith(['active']);
        $stale = ServiceSubscription::query()->findOrFail($subscription->id);

        SubscriptionService::terminate($subscription, 'first');

        try {
            SubscriptionService::terminate($stale, 'second');
            $this->fail('A subscription that already ended cannot end again.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Langganan sudah berhenti.', $e->getMessage());
        }

        $this->assertSame('first', $subscription->fresh()->terminated_reason);
        $this->assertSame(1, $this->audits($subscription, 'terminated'));
    }

    public function test_the_roll_up_reads_subscriptions_under_lock(): void
    {
        [$customer, [, $second]] = $this->customerWith(['active', 'suspended']);
        $this->commitFixture();

        try {
            $this->assertTrue(
                $this->waitsForRowLock('service_subscriptions', $second->id, fn () => CustomerStatusRecalculator::recalculate($customer->id)),
                'the roll-up waits for a subscription that a transition is still writing',
            );
            $this->assertFalse(
                $this->waitsForRowLock('service_subscriptions', $second->id, fn () => CustomerStatusRecalculator::expected($customer)),
                'the dry-run read stays a plain read',
            );
        } finally {
            $this->purgeCompany($customer->company_id);
        }
    }

    public function test_two_subscriptions_of_one_customer_suspended_together_leave_it_suspended(): void
    {
        [$customer, [$first, $second]] = $this->customerWith(['active', 'active']);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $customer->company_id, 'subscription_id' => $first->id],
                ['company_id' => $customer->company_id, 'subscription_id' => $second->id],
            ], self::SUSPEND);

            $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
            $this->assertSame(['suspended', 'suspended'], [$this->statusOf($first), $this->statusOf($second)]);
            $this->assertSame('suspended', Customer::withoutCompany()->findOrFail($customer->id)->customer_status);
        } finally {
            $this->purgeCompany($customer->company_id);
        }
    }

    public function test_two_subscriptions_of_one_customer_terminated_together_leave_it_terminated(): void
    {
        [$customer, [$first, $second]] = $this->customerWith(['active', 'active']);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $customer->company_id, 'subscription_id' => $first->id, 'reason' => 'moved away'],
                ['company_id' => $customer->company_id, 'subscription_id' => $second->id, 'reason' => 'moved away'],
            ], self::TERMINATE);

            $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
            $this->assertSame(['terminated', 'terminated'], [$this->statusOf($first), $this->statusOf($second)]);
            $this->assertSame('terminated', Customer::withoutCompany()->findOrFail($customer->id)->customer_status, 'the roll-up saw both endings, not the snapshot it took before the other commit');
        } finally {
            $this->purgeCompany($customer->company_id);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function transitions(): array
    {
        return ['create' => ['create'], 'activate' => ['activate'], 'suspend' => ['suspend'], 'reactivate' => ['reactivate'], 'terminate' => ['terminate']];
    }

    #[DataProvider('transitions')]
    public function test_a_transition_locks_the_customer_row_before_it_touches_the_subscription(string $transition): void
    {
        [, [$subscription]] = $this->customerWith([['activate' => 'pending', 'reactivate' => 'suspended'][$transition] ?? 'active']);

        DB::enableQueryLog();

        try {
            match ($transition) {
                'create' => SubscriptionService::create([
                    'customer_id' => $subscription->customer_id,
                    'service_package_id' => $subscription->service_package_id,
                    'installation_address_id' => $subscription->installation_address_id,
                    'billing_day' => 5,
                ]),
                'activate' => SubscriptionService::activate($subscription),
                'suspend' => SubscriptionService::suspend($subscription, 'unpaid'),
                'reactivate' => SubscriptionService::reactivate($subscription),
                'terminate' => SubscriptionService::terminate($subscription, 'moved away'),
            };
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        $customerLock = $subscriptionTouch = null;

        foreach ($queries as $index => $sql) {
            $sql = trim($sql);
            $customerLock ??= str_contains($sql, 'from `customers`') && str_ends_with($sql, 'for update') ? $index : null;
            $subscriptionTouch ??= str_contains($sql, '`service_subscriptions`') ? $index : null;
        }

        $this->assertNotNull($customerLock, "{$transition} locks the customer row");
        $this->assertNotNull($subscriptionTouch, "{$transition} reaches the subscription");
        $this->assertLessThan($subscriptionTouch, $customerLock, "{$transition} queues on the customer row before it touches a subscription");
    }
}
