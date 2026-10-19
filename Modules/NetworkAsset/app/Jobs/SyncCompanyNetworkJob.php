<?php

namespace Modules\NetworkAsset\Jobs;

use App\Services\Core\CompanyService;
use App\Services\Core\IntegrationConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;

/**
 * The sweep of `network:sync` for one company: writes its NAS clients that are not in its RADIUS database yet and
 * re-queues its accounts that are pending, owe a Disconnect, failed or drifted. The RADIUS server is the company's own,
 * so one that does not answer must stall that company's sweep only: it is a job of its own, and the worker kills it at the
 * timeout while the jobs of the other companies run on. Carries the company id only (the failed-job reporter logs it).
 *
 * Unique per company, so a sweep that is still running is not queued again. No retry: the next sweep comes in 15 minutes.
 */
class SyncCompanyNetworkJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Below retry_after (180 s). */
    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    /** Safety net for a lock whose job never reached a worker: a later sweep is accepted again. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $companyId) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(NetworkAccountProvisioner $provisioner): void
    {
        try {
            CompanyService::runAs($this->companyId, function () use ($provisioner): void {
                // The integration may have been switched off since the command queued this job.
                if (! app(IntegrationConfig::class)->isEnabled('radius')) {
                    return;
                }

                $this->writeNasRows($provisioner);
                $this->queueAccounts($provisioner);
            });
        } finally {
            // A worker runs one job after the other: a link left open here would stay until its loop purges it.
            DB::purge(RadiusDirectory::connectionName($this->companyId));
        }
    }

    private function writeNasRows(NetworkAccountProvisioner $provisioner): void
    {
        NasDevice::query()
            ->with('networkAsset:id,code,name')
            ->where(fn ($query) => $query->whereNull('radius_synced_at')->orWhereNull('radius_nasname')->orWhereColumn('nasname', '!=', 'radius_nasname'))
            ->whereNotNull('nasname')
            ->whereNotNull('radius_secret')
            ->each(function (NasDevice $nas) use ($provisioner): void {
                $provisioner->writeNas($nas);
            });
    }

    private function queueAccounts(NetworkAccountProvisioner $provisioner): void
    {
        $driftCheck = true;

        NetworkAccount::query()->with('subscription.packageVersion.speedProfile')->orderBy('id')->each(function (NetworkAccount $account) use ($provisioner, &$driftCheck): void {
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
                ApplyNetworkAccountJob::dispatch($this->companyId, $account->id);
            }
        });
    }
}
