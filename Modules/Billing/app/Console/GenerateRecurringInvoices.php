<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;

class GenerateRecurringInvoices extends Command
{
    public function __construct(private readonly BillingScheduleGateway $gateway)
    {
        parent::__construct();
    }

    protected $signature = 'billing:generate {--period= : Billing period YYYY-MM (default: previous month)} {--dry-run : Compute without creating invoices} {--company= : Explicit company id for artisan runs}';

    protected $description = 'Generate postpaid recurring invoices for a billing period';

    public function handle(): int
    {
        $period = $this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        $dryRun = (bool) $this->option('dry-run');
        $companyIds = $this->resolveCompanyIds();

        try {
            foreach ($companyIds as $companyId) {
                $result = CompanyService::runAs($companyId, fn () => $this->gateway->generateForPeriod($period, $dryRun, $companyId));

                $this->table(
                    ['Subscription', 'Customer', 'Package', 'Days', 'Amount', 'Tax', 'Total'],
                    array_map(fn ($r) => [
                        $r['subscription_code'],
                        $r['customer'],
                        $r['package'],
                        "{$r['active_days']}/{$r['days_in_period']}",
                        number_format($r['amount'], 2),
                        number_format($r['tax'], 2),
                        number_format($r['total'], 2),
                    ], $result['rows']),
                );

                $this->info(($dryRun ? '[DRY RUN] ' : '')."Company {$companyId} — Period {$period} — created: {$result['created']}, skipped: {$result['skipped']}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Billing generate failed: '.$e->getMessage());

            Notification::send(
                User::where('is_active', true)->role('admin')->get(),
                new BillingScheduleFailedNotification(command: 'billing:generate'),
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
