<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerStatusRecalculator;
use Illuminate\Console\Command;

class RecalculateCustomerStatusCommand extends Command
{
    protected $signature = 'customers:recalculate-status
        {--company= : Company id; default all active companies}
        {--dry-run : Print the changes without writing}';

    protected $description = 'Recompute customers.customer_status from subscriptions (drift repair, idempotent).';

    public function handle(): int
    {
        $explicit = $this->option('company');
        $companyIds = $explicit !== null
            ? [(int) $explicit]
            : Company::query()->where('is_active', true)->pluck('id')->all();
        $dryRun = (bool) $this->option('dry-run');
        $changes = 0;

        foreach ($companyIds as $companyId) {
            CompanyService::runAs($companyId, function () use ($dryRun, &$changes): void {
                Customer::query()->chunkById(200, function ($customers) use ($dryRun, &$changes): void {
                    foreach ($customers as $customer) {
                        $to = $dryRun
                            ? CustomerStatusRecalculator::expected($customer)
                            : CustomerStatusRecalculator::recalculate($customer->id);

                        if ($customer->customer_status !== $to) {
                            $changes++;
                            $this->line("{$customer->code}: {$customer->customer_status} -> {$to}");
                        }
                    }
                });
            });
        }

        $this->info(($dryRun ? 'Would change ' : 'Changed ').$changes.' customer(s).');

        return self::SUCCESS;
    }
}
