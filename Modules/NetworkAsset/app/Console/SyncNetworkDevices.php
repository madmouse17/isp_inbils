<?php

namespace Modules\NetworkAsset\Console;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Modules\NetworkAsset\Jobs\SyncNasDeviceJob;
use Modules\NetworkAsset\Models\NasDevice;

class SyncNetworkDevices extends Command
{
    protected $signature = 'network:sync {--company= : Company id} {--nas= : NAS device id}';

    protected $description = 'Queue the 15-minute sync of every NAS router (reachability, online accounts, drift, stray secrets)';

    public function handle(): int
    {
        if (! config('services.routeros.enabled')) {
            $this->info('Router provisioning is switched off for this installation (ROUTEROS_PROVISIONING_ENABLED); nothing to sync.');

            return self::SUCCESS;
        }

        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : Company::query()->where('is_active', true)->pluck('id')->all();
        $queued = 0;

        // One job per router under the router's own company, like the per-tenant SLA check.
        foreach ($companyIds as $companyId) {
            $nasIds = CompanyService::runAs($companyId, fn () => NasDevice::query()
                ->when($this->option('nas') !== null, fn ($query) => $query->whereKey((int) $this->option('nas')))
                ->pluck('id'));

            foreach ($nasIds as $nasId) {
                SyncNasDeviceJob::dispatch($companyId, (int) $nasId);
                $queued++;
            }
        }

        $this->info("Network sync queued for {$queued} NAS routers.");

        return self::SUCCESS;
    }
}
