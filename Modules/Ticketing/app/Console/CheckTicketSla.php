<?php

declare(strict_types=1);

namespace Modules\Ticketing\Console;

use App\Models\Core\Company;
use Illuminate\Console\Command;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;

class CheckTicketSla extends Command
{
    protected $signature = 'tickets:sla {--company= : Company ID to check SLA for}';

    protected $description = 'Check for SLA breaches and fire events for notifications';

    public function handle(): int
    {
        $companyId = $this->option('company');

        if ($companyId !== null) {
            CheckTicketSlaBreachesJob::dispatch((int) $companyId);
            $this->info("SLA check dispatched for company {$companyId}.");
        } else {
            // Dispatch one job per active company so each tenant's
            // SLA breaches are checked under the correct tenant context.
            $activeCompanies = Company::query()
                ->where('is_active', true)
                ->pluck('id');

            if ($activeCompanies->isEmpty()) {
                $this->info('No active companies.');

                return self::SUCCESS;
            }

            foreach ($activeCompanies as $companyId) {
                CheckTicketSlaBreachesJob::dispatch((int) $companyId);
            }

            $this->info("SLA check dispatched for {$activeCompanies->count()} active companies.");
        }

        return self::SUCCESS;
    }
}
