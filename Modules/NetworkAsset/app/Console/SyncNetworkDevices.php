<?php

namespace Modules\NetworkAsset\Console;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Jobs\SyncCompanyNetworkJob;

class SyncNetworkDevices extends Command
{
    protected $signature = 'network:sync {--company= : Company id}';

    protected $description = 'Queue the RADIUS sync of every company that has RADIUS set up: write unsynced NAS clients and re-queue accounts that are pending, owe a Disconnect, failed or drifted';

    /**
     * One queued job per company, never the sweep itself: a RADIUS server is the company's own, and one that does not
     * answer must not hold up the companies behind it (SyncCompanyNetworkJob).
     */
    public function handle(): int
    {
        // Every company syncs into its own RADIUS database; one without an enabled integration has nothing to sync.
        $companyIds = CompanyIntegration::withoutCompany()
            ->where('provider', 'radius')
            ->where('is_enabled', true)
            ->when(
                $this->option('company') !== null,
                fn (Builder $query) => $query->where('company_id', (int) $this->option('company')),
                fn (Builder $query) => $query->whereIn('company_id', Company::query()->where('is_active', true)->select('id')),
            )
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            SyncCompanyNetworkJob::dispatch((int) $companyId);
        }

        $this->info($companyIds->isEmpty()
            ? 'RADIUS is not configured for any company; nothing to sync.'
            : "Network sync queued for {$companyIds->count()} ".Str::plural('company', $companyIds->count()).'.');

        return self::SUCCESS;
    }
}
