<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\BillingService;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'billing:generate {--period= : Billing period YYYY-MM (default: previous month)} {--dry-run : Compute without creating invoices}';

    protected $description = 'Generate postpaid recurring invoices for a billing period';

    public function handle(): int
    {
        $period = $this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        $dryRun = (bool) $this->option('dry-run');

        $result = BillingService::generateForPeriod($period, $dryRun);

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

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Period {$period} — created: {$result['created']}, skipped: {$result['skipped']}");

        return self::SUCCESS;
    }
}
