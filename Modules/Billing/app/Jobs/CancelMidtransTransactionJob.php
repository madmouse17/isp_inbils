<?php

namespace Modules\Billing\Jobs;

use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use Throwable;

/**
 * Cancels at Midtrans the transaction of a payment page Inbils closed, so a customer who still has the page open cannot
 * pay it. The call stays out of the web request that closed the page (a slow Midtrans must not hold an invoice lock) and
 * is tried again while Midtrans is unreachable. Carries ids only and runs inside the page's company.
 */
class CancelMidtransTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** One Midtrans call takes at most 15 s (10 s timeout, 5 s connect); well below the queue's retry_after. */
    public int $timeout = 30;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $companyId, public readonly int $attemptId) {}

    public function handle(MidtransGateway $gateway): void
    {
        CompanyService::runAs($this->companyId, function () use ($gateway): void {
            $attempt = PaymentAttempt::query()->find($this->attemptId);

            // A page that settled or was reopened since has nothing to cancel, and a gateway that is off has no transaction to reach.
            if ($attempt?->status !== PaymentAttempt::STATUS_CANCELLED_LOCALLY || ! $gateway->isEnabled()) {
                return;
            }

            $gateway->cancelTransaction($attempt);
        });
    }

    /** The page stays closed here; a payment that still arrives for it lands as an exception, which billing staff are told about. */
    public function failed(Throwable $e): void
    {
        Log::warning('midtrans.cancel_failed', ['order_id' => PaymentAttempt::withoutCompany()->whereKey($this->attemptId)->value('order_id')]);
    }
}
