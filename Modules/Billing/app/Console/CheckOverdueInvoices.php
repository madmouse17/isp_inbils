<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use Illuminate\Console\Command;
use Modules\Billing\Services\BillingService;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'billing:check-overdue {--company= : Explicit company id for artisan runs}';

    protected $description = 'Mark past-due sent/partial invoices as overdue';

    public function handle(): int
    {
        $companyIds = $this->resolveCompanyIds();

        foreach ($companyIds as $companyId) {
            BillingService::checkOverdue($companyId);
            $this->info("Overdue check complete for company {$companyId}.");
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function resolveCompanyIds(): array
    {
        $explicit = $this->option('company');

        if ($explicit !== null) {
            return [(int) $explicit];
        }

        return Company::where('is_active', true)->pluck('id')->all();
    }
}
