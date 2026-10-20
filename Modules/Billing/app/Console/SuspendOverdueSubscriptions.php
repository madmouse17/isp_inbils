<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Notifications\SubscriptionsIsolatedSummaryNotification;
use App\Services\Core\CompanyService;
use App\Support\Notifications\StaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;
use Throwable;

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
        $failedCompanyIds = [];

        // One company's failure must not keep the others from being isolated: it is reported, and only its own admins are told, at the end.
        foreach ($this->resolveCompanyIds() as $companyId) {
            try {
                $suspended = CompanyService::runAs($companyId, fn () => $this->gateway->suspendOverdue($companyId, $dryRun));

                foreach ($suspended as $subscription) {
                    $this->line(($dryRun ? 'Would suspend ' : 'Suspended ').$subscription['code']);
                }

                $this->info(($dryRun ? "[DRY RUN] Company {$companyId} — would suspend: " : "Company {$companyId} — suspended: ").count($suspended));

                if (! $dryRun && $suspended !== []) {
                    $this->tellStaff($companyId, count($suspended));
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("Overdue suspension failed for company {$companyId}: ".$e->getMessage());
                $failedCompanyIds[] = $companyId;
            }
        }

        BillingScheduleFailedNotification::alertAdmins('billing:suspend-overdue', $failedCompanyIds);

        return $failedCompanyIds === [] ? self::SUCCESS : self::FAILURE;
    }

    /** One bell row for each holder of the isolate permission, not one per subscription. A failure here never undoes an isolation. */
    private function tellStaff(int $companyId, int $count): void
    {
        rescue(fn () => Notification::send(
            StaffRecipients::holding($companyId, 'customer.subscription.suspend'),
            new SubscriptionsIsolatedSummaryNotification($companyId, $count),
        ));
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
