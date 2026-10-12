<?php

namespace Modules\NetworkAsset\Console;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\Radius\RadiusException;

class SyncNetworkDevices extends Command
{
    protected $signature = 'network:sync {--company= : Company id}';

    protected $description = 'Write unsynced NAS clients to RADIUS and re-queue accounts that are pending, owe a Disconnect, failed or drifted';

    public function handle(NetworkAccountProvisioner $provisioner): int
    {
        if (! config('services.radius.enabled')) {
            $this->info('RADIUS is switched off for this installation (RADIUS_ENABLED); nothing to sync.');

            return self::SUCCESS;
        }

        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : Company::query()->where('is_active', true)->pluck('id')->all();
        $nasWritten = 0;
        $queued = 0;

        foreach ($companyIds as $companyId) {
            CompanyService::runAs($companyId, function () use ($provisioner, $companyId, &$nasWritten, &$queued): void {
                $nasWritten += $this->writeNasRows($provisioner);
                $queued += $this->queueAccounts($provisioner, $companyId);
            });
        }

        $this->info("Network sync wrote {$nasWritten} NAS clients and queued {$queued} accounts.");

        return self::SUCCESS;
    }

    private function writeNasRows(NetworkAccountProvisioner $provisioner): int
    {
        $written = 0;

        NasDevice::query()
            ->with('networkAsset:id,code,name')
            ->where(fn ($query) => $query->whereNull('radius_synced_at')->orWhereNull('radius_nasname')->orWhereColumn('nasname', '!=', 'radius_nasname'))
            ->whereNotNull('nasname')
            ->whereNotNull('radius_secret')
            ->each(function (NasDevice $nas) use ($provisioner, &$written): void {
                $written += $provisioner->writeNas($nas) ? 1 : 0;
            });

        return $written;
    }

    private function queueAccounts(NetworkAccountProvisioner $provisioner, int $companyId): int
    {
        $queued = 0;
        $driftCheck = true;

        NetworkAccount::query()->with('subscription.servicePackage.speedProfile')->orderBy('id')->each(function (NetworkAccount $account) use ($provisioner, $companyId, &$queued, &$driftCheck): void {
            $stuck = $account->sync_status === 'pending'
                || $account->pending_disconnect_at !== null
                || ($account->sync_status === 'failed' && $account->last_attempt_at?->lt(now()->subHour()) === true);

            if (! $stuck && $driftCheck) {
                try {
                    $stuck = $provisioner->hasDrifted($account);
                } catch (RadiusException) {
                    // RADIUS is down: the pending accounts are still queued, the drift check resumes next run.
                    $driftCheck = false;
                }
            }

            if ($stuck) {
                ApplyNetworkAccountJob::dispatch($companyId, $account->id);
                $queued++;
            }
        });

        return $queued;
    }
}
