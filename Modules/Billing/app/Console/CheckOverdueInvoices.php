<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\BillingService;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'billing:check-overdue';

    protected $description = 'Mark past-due sent/partial invoices as overdue';

    public function handle(): int
    {
        BillingService::checkOverdue();
        $this->info('Overdue check complete.');

        return self::SUCCESS;
    }
}
