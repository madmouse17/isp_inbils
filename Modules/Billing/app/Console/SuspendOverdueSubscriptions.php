<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;

class SuspendOverdueSubscriptions extends Command
{
    public function __construct(private readonly BillingScheduleGateway $gateway)
    {
        parent::__construct();
    }

    protected $signature = 'billing:suspend-overdue {--company= : Explicit company id for artisan runs} {--dry-run : List the subscriptions without suspending them}';

    protected $description = 'Isolate active subscriptions with an invoice past the company grace days (companies that enabled auto_suspend_enabled)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            foreach ($this->resolveCompanyIds() as $companyId) {
                $suspended = CompanyService::runAs($companyId, fn () => $this->gateway->suspendOverdue($companyId, $dryRun));

                foreach ($suspended as $subscription) {
                    $this->line(($dryRun ? 'Would suspend ' : 'Suspended ').$subscription['code']);
                }

                $this->info(($dryRun ? "[DRY RUN] Company {$companyId} — would suspend: " : "Company {$companyId} — suspended: ").count($suspended));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Overdue suspension failed: '.$e->getMessage());

            Notification::send(
                User::where('is_active', true)->role('admin')->get(),
                new BillingScheduleFailedNotification(command: 'billing:suspend-overdue'),
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
