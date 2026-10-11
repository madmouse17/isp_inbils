<?php

namespace Modules\Billing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice became payable for the customer: a recurring invoice was generated, or a draft was sent.
 * Dispatched after the transaction commits, so listeners never see an invoice that was rolled back. Not
 * dispatched for drafts, cancellations or payment reversals.
 */
final class InvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
    ) {}
}
