<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;

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
        $companyIds = $this->resolveCompanyIds();

        try {
            foreach ($companyIds as $companyId) {
                CompanyService::runAs($companyId, fn () => $this->gateway->checkOverdue($companyId));
                $this->info("Overdue check complete for company {$companyId}.");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Overdue check failed: '.$e->getMessage());

            Notification::send(
                User::where('is_active', true)->role('admin')->get(),
                new BillingScheduleFailedNotification(command: 'billing:check-overdue'),
            );

            return self::FAILURE;
        }
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
