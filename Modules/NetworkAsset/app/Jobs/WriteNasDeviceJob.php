<?php

namespace Modules\NetworkAsset\Jobs;

use App\Services\Core\CompanyService;
use App\Services\Core\IntegrationConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;

/**
 * Writes one NAS router to its company's RADIUS database now: the "Sinkronkan ke RADIUS" button of the router page,
 * so the operator does not wait for the 15-minute sweep (`network:sync`) after the RADIUS server came back. Carries ids
 * only. A failed write is left on the row (`last_error`) by the provisioner and never thrown, so there is no retry here:
 * the sweep is the retry.
 *
 * Unique until processing starts: a double click queues one run.
 */
class WriteNasDeviceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Below the worker timeout (90 s) and retry_after (180 s). */
    public int $timeout = 60;

    public int $tries = 1;

    /** Safety net for a lock whose job never reached a worker. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $companyId, public readonly int $nasDeviceId) {}

    public function uniqueId(): string
    {
        return "{$this->companyId}:{$this->nasDeviceId}";
    }

    public function handle(NetworkAccountProvisioner $provisioner): void
    {
        try {
            CompanyService::runAs($this->companyId, function () use ($provisioner): void {
                $nas = NasDevice::query()->with('networkAsset:id,code,name')->find($this->nasDeviceId);

                // The integration may have been switched off, or the router removed, since the button queued this run.
                if ($nas !== null && app(IntegrationConfig::class)->isEnabled('radius')) {
                    $provisioner->writeNas($nas);
                }
            });
        } finally {
            // A worker runs one job after the other: a link left open here would stay until its loop purges it.
            DB::purge(RadiusDirectory::connectionName($this->companyId));
        }
    }
}
