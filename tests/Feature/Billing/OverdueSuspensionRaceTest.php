<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\OverdueSuspensionService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

/**
 * MIKROTIK-07-002: the automatic isolir lists the eligible subscriptions once and suspends them one by one, so
 * what it listed can change in between. Each suspension re-checks its invoices inside its own transaction.
 * The `retrieved` hook fires right after the list was read, before the first subscription is suspended.
 */
class OverdueSuspensionRaceTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->company = $this->createCompanyUser()->company;
        $this->company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
    }

    /** @return list<array{id: int, code: string}> */
    private function runIsolir(): array
    {
        return CompanyService::runAs((int) $this->company->id, fn () => OverdueSuspensionService::suspendOverdue((int) $this->company->id));
    }

    private function afterTheListWasRead(callable $change): void
    {
        $fired = false;

        ServiceSubscription::retrieved(function () use (&$fired, $change): void {
            if ($fired) {
                return;
            }

            $fired = true;
            $change();
        });
    }

    public function test_a_payment_that_lands_after_the_list_was_read_keeps_the_subscription_active(): void
    {
        $paying = $this->subscriptionIn($this->company);
        $invoice = $this->invoiceFor($paying, 30);
        $other = $this->subscriptionIn($this->company);
        $this->invoiceFor($other, 30);
        $this->afterTheListWasRead(fn () => BillingService::recordPayment($invoice, 100000, 'cash'));

        $suspended = $this->runIsolir();

        $this->assertSame('paid', Invoice::withoutCompany()->findOrFail($invoice->id)->status);
        $this->assertSame('active', $this->freshSubscription($paying)->status);
        $this->assertSame('suspended', $this->freshSubscription($other)->status);
        $this->assertSame([$other->code], array_column($suspended, 'code'));
        $this->assertSame(1, Activity::query()->where('description', 'suspended')->count());
    }

    public function test_an_invoice_cancelled_after_the_list_was_read_no_longer_counts(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $invoice = $this->invoiceFor($subscription, 30);
        $this->afterTheListWasRead(fn () => Invoice::withoutCompany()->whereKey($invoice->id)->update(['status' => 'cancelled']));

        $suspended = $this->runIsolir();

        $this->assertSame([], $suspended);
        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_a_subscription_that_was_suspended_by_hand_after_the_list_was_read_is_left_alone(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 30);
        $this->afterTheListWasRead(fn () => ServiceSubscription::withoutCompany()->whereKey($subscription->id)->update(['status' => 'suspended', 'suspension_source' => 'manual']));

        $suspended = $this->runIsolir();

        $this->assertSame([], $suspended);
        $this->assertSame('manual', $this->freshSubscription($subscription)->suspension_source);
        $this->assertSame(0, Activity::query()->where('description', 'suspended')->count());
    }
}
