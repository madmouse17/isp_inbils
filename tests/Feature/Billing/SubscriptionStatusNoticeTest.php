<?php

namespace Tests\Feature\Billing;

use App\Http\Resources\NotificationResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DeviceToken;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The customer hears it when their service is isolated or comes back (bell, and push with the app), and the staff who may
 * isolate hear once a day how many the automatic run isolated. Rows carry the keys NotificationResource renders.
 */
class SubscriptionStatusNoticeTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->company = $this->createCompanyUser()->company;
        $this->enableAutoSuspend($this->company);
        $this->fakeFirebase();
    }

    private function enableAutoSuspend(Company $company): void
    {
        $company->update(['name' => 'PT Fiber Nusantara', 'settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
    }

    /** A subscription of a customer who has the app: a customer login and an install a push can reach. */
    private function subscriptionWithApp(string $status = 'active'): ServiceSubscription
    {
        [$login, $customer] = $this->customerLogin($this->company);
        $subscription = $this->subscriptionIn($this->company, $status);
        $subscription->forceFill(['customer_id' => $customer->id])->save();
        $subscription->setRelation('customer', $customer);
        DeviceToken::factory()->forApp('customer')->create(['user_id' => $login->id, 'token' => 'customer-phone-'.$login->id]);

        return $subscription;
    }

    /**
     * What the app lists for a notifiable, as GET /notifications renders the bell rows.
     *
     * @return list<array<string, mixed>>
     */
    private function bell(User|Customer $notifiable): array
    {
        return $notifiable->notifications()->get()
            ->map(fn (DatabaseNotification $row): array => (new NotificationResource($row))->resolve(Request::create('/api/v1/notifications')))
            ->all();
    }

    /** @return list<string> */
    private function types(ServiceSubscription $subscription): array
    {
        return array_column($this->bell($subscription->customer), 'type');
    }

    public function test_overdue_suspension_notifies_customer_with_invoice_numbers(): void
    {
        $subscription = $this->subscriptionWithApp();
        $invoice = $this->invoiceFor($subscription, 10);
        $second = $this->invoiceFor($subscription, 9);

        $this->artisan('billing:suspend-overdue', ['--company' => $this->company->id])->assertSuccessful();

        $bell = $this->bell($subscription->customer);
        $this->assertCount(1, $bell);
        $this->assertSame('subscription_isolated', $bell[0]['type']);
        $this->assertSame('Layanan diisolir', $bell[0]['title']);
        $this->assertSame(
            "Layanan {$subscription->code} diisolir karena tagihan {$invoice->number}, {$second->number} lewat jatuh tempo. Bayar agar layanan aktif kembali.",
            $bell[0]['message'],
        );
        $this->assertSame(['type' => 'subscription', 'id' => strtolower($subscription->public_id)], $bell[0]['subject']);

        $this->assertSame(['customer-phone-'.$subscription->customer->user_id], $this->pushedTokens());
        $push = $this->onlyPush();
        $this->assertSame(['title' => 'Layanan diisolir', 'body' => $bell[0]['message']], $push['notification']);
        $this->assertSame(
            ['type' => 'subscription_isolated', 'subject_type' => 'subscription', 'subject_id' => strtolower($subscription->public_id)],
            array_intersect_key($push['data'], array_flip(['type', 'subject_type', 'subject_id'])),
        );
    }

    public function test_the_row_carries_only_what_the_bell_renders(): void
    {
        $subscription = $this->subscriptionWithApp();
        $this->invoiceFor($subscription, 10);

        SubscriptionService::suspend($subscription, 'late', 'overdue');

        $data = $subscription->customer->notifications()->firstOrFail()->data;
        $this->assertEqualsCanonicalizing(['type', 'subscription_id', 'subscription_code', 'cause', 'invoice_numbers'], array_keys($data), 'the database sorts the keys of a JSON column');
        $this->assertSame([$subscription->public_id, $subscription->code, 'overdue'], [$data['subscription_id'], $data['subscription_code'], $data['cause']]);
    }

    public function test_manual_block_notifies_limited(): void
    {
        $blocked = $this->subscriptionWithApp();
        $suspended = $this->subscriptionWithApp();

        SubscriptionService::block($blocked, 'abuse');
        SubscriptionService::suspend($suspended, 'customer asked');

        foreach ([[$blocked, 'blocked'], [$suspended, 'manual']] as [$subscription, $cause]) {
            $bell = $this->bell($subscription->customer);
            $this->assertCount(1, $bell, $cause);
            $this->assertSame('Layanan dibatasi', $bell[0]['title'], $cause);
            $this->assertSame("Layanan {$subscription->code} sedang dibatasi oleh PT Fiber Nusantara. Hubungi kami untuk informasi.", $bell[0]['message'], $cause);
            $this->assertSame($cause, $subscription->customer->notifications()->firstOrFail()->data['cause']);
        }
    }

    public function test_reactivation_notifies(): void
    {
        $subscription = $this->subscriptionWithApp();
        SubscriptionService::suspend($subscription, 'customer asked');

        SubscriptionService::reactivate($subscription);

        $this->assertEqualsCanonicalizing(['subscription_isolated', 'subscription_reactivated'], $this->types($subscription));
        $back = collect($this->bell($subscription->customer))->firstWhere('type', 'subscription_reactivated');
        $this->assertSame('Layanan aktif kembali', $back['title']);
        $this->assertSame("Layanan {$subscription->code} sudah aktif kembali.", $back['message']);
        $this->assertSame(['type' => 'subscription', 'id' => strtolower($subscription->public_id)], $back['subject']);
        $this->assertCount(2, $this->pushedTokens(), 'both reach the phone');
    }

    public function test_unblocking_an_active_subscription_notifies_the_return(): void
    {
        $subscription = $this->subscriptionWithApp();
        SubscriptionService::block($subscription, 'abuse');

        SubscriptionService::unblock($subscription);

        $this->assertEqualsCanonicalizing(['subscription_isolated', 'subscription_reactivated'], $this->types($subscription));
    }

    public function test_only_the_first_isolation_and_the_last_release_are_told(): void
    {
        $subscription = $this->subscriptionWithApp();

        SubscriptionService::suspend($subscription, 'unpaid', 'overdue');
        SubscriptionService::block($subscription, 'fraud');
        $this->assertSame(['subscription_isolated'], $this->types($subscription), 'a block on top of a suspension changes nothing the customer sees');

        SubscriptionService::reactivate($subscription);
        $this->assertSame(['subscription_isolated'], $this->types($subscription), 'still blocked: still isolated');

        SubscriptionService::unblock($subscription);
        $this->assertEqualsCanonicalizing(['subscription_isolated', 'subscription_reactivated'], $this->types($subscription));

        $another = $this->subscriptionWithApp();
        SubscriptionService::block($another, 'abuse');
        SubscriptionService::suspend($another, 'unpaid', 'overdue');
        SubscriptionService::unblock($another);
        $this->assertSame(['subscription_isolated'], $this->types($another), 'unblocked but still suspended: still isolated');
    }

    public function test_a_subscription_that_is_not_served_anyway_tells_nobody(): void
    {
        $pending = $this->subscriptionWithApp('pending');

        SubscriptionService::block($pending, 'abuse');
        SubscriptionService::unblock($pending);

        $this->assertSame([], $this->types($pending));
    }

    public function test_a_customer_without_the_app_gets_no_row_and_no_push(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        SubscriptionService::suspend($subscription, 'customer asked');
        SubscriptionService::reactivate($subscription);

        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame([], $this->pushedTokens());
    }

    public function test_a_rolled_back_change_tells_nobody(): void
    {
        $subscription = $this->subscriptionWithApp();

        try {
            DB::transaction(function () use ($subscription): void {
                SubscriptionService::suspend($subscription, 'customer asked');

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame([], $this->pushedTokens());
    }

    public function test_daily_run_sends_one_summary_per_company(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $this->enableAutoSuspend($other);
        $firstHolder = $this->userWithPermissions($this->company, 'customer.subscription.suspend');
        $secondHolder = $this->userWithPermissions($this->company, 'customer.subscription.suspend', 'billing.view');
        $bystander = $this->userWithPermissions($this->company, 'customer.subscription.view');
        $otherHolder = $this->userWithPermissions($other, 'customer.subscription.suspend');
        foreach ([$this->company, $this->company, $other] as $company) {
            $this->invoiceFor($this->subscriptionIn($company), 10);
        }

        $this->artisan('billing:suspend-overdue')->assertSuccessful();

        foreach ([[$firstHolder, 2, '2 langganan diisolir otomatis karena tunggakan.'], [$secondHolder, 2, '2 langganan diisolir otomatis karena tunggakan.'], [$otherHolder, 1, '1 langganan diisolir otomatis karena tunggakan.']] as [$holder, $count, $message]) {
            $bell = $this->bell($holder);
            $this->assertCount(1, $bell);
            $this->assertSame(['subscriptions_isolated_summary', 'Isolir otomatis hari ini', $message, null], [$bell[0]['type'], $bell[0]['title'], $bell[0]['message'], $bell[0]['subject']]);
            $this->assertSame($count, $holder->notifications()->firstOrFail()->data['count']);
        }
        $this->assertSame([], $this->bell($bystander), 'nobody else is told');
        $this->assertEqualsCanonicalizing(['type', 'count', 'company_id'], array_keys($firstHolder->notifications()->firstOrFail()->data));
        $this->assertSame($this->company->id, $firstHolder->notifications()->firstOrFail()->data['company_id']);
        $this->assertSame([], $this->pushedTokens(), 'a daily batch wakes no phone');

        $webLink = fn (User $user): ?string => (new NotificationResource($user->notifications()->firstOrFail()))
            ->resolve(tap(Request::create('/admin/notifications'), fn (Request $request) => $request->setUserResolver(fn () => $user)))['detail_url'];
        $this->assertSame(route('admin.billing.receivables'), $webLink($secondHolder), 'the bell of someone who may see Tunggakan opens it');
        $this->assertNull($webLink($firstHolder), 'and opens nothing for someone who may not');
    }

    public function test_a_dry_run_and_a_run_that_isolates_nothing_send_no_summary(): void
    {
        $holder = $this->userWithPermissions($this->company, 'customer.subscription.suspend');
        $this->invoiceFor($this->subscriptionIn($this->company), 10);

        $this->artisan('billing:suspend-overdue', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame([], $this->bell($holder));

        $this->artisan('billing:suspend-overdue')->assertSuccessful();
        $this->assertCount(1, $this->bell($holder));

        $this->artisan('billing:suspend-overdue')->assertSuccessful();
        $this->assertCount(1, $this->bell($holder), 'the second run found nothing left to isolate');
    }
}
