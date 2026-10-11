<?php

namespace Modules\Billing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was committed against an invoice (manual and Midtrans payments alike). Dispatched after the
 * payment transaction commits, so listeners never see a payment that was rolled back.
 */
final class PaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
        public readonly int $paymentId,
    ) {}
}
