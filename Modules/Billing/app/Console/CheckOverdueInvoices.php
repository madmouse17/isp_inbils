<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;
use Throwable;

class CheckOverdueInvoices extends Command
{
    public function __construct(private readonly BillingScheduleGateway $gateway)
    {
        parent::__construct();
    }

    protected $signature = 'billing:check-overdue {--company= : Explicit company id for artisan runs}';

    protected $description = 'Mark past-due sent/partial invoices as overdue';

    public function handle(): int
    {
        $failedCompanyIds = [];

        // One company's failure must not keep the others from being checked: it is reported, and only its own admins are told, at the end.
        foreach ($this->resolveCompanyIds() as $companyId) {
            try {
                CompanyService::runAs($companyId, fn () => $this->gateway->checkOverdue($companyId));
                $this->info("Overdue check complete for company {$companyId}.");
            } catch (Throwable $e) {
                report($e);
                $this->error("Overdue check failed for company {$companyId}: ".$e->getMessage());
                $failedCompanyIds[] = $companyId;
            }
        }

        BillingScheduleFailedNotification::alertAdmins('billing:check-overdue', $failedCompanyIds);

        return $failedCompanyIds === [] ? self::SUCCESS : self::FAILURE;
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
