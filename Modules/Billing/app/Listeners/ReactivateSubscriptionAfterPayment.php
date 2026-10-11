<?php

namespace Modules\Billing\Listeners;

use App\Services\Core\CompanyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\PaymentRecorded;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\OverdueSuspensionService;

/**
 * Queued so a problem while reconnecting can never fail the payment that is already committed.
 */
class ReactivateSubscriptionAfterPayment implements ShouldQueue
{
    public function handle(PaymentRecorded $event): void
    {
        $subscriptionId = Invoice::withoutCompany()
            ->where('company_id', $event->companyId)
            ->whereKey($event->invoiceId)
            ->value('subscription_id');

        if ($subscriptionId === null) {
            return;
        }

        CompanyService::runAs($event->companyId, fn () => OverdueSuspensionService::reactivateIfCleared($event->companyId, (int) $subscriptionId));
    }
}
