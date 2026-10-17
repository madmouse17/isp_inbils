<?php

namespace App\Jobs\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use App\Services\Platform\TenantExportBuilder;
use App\Services\Platform\TenantExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class BuildTenantExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const STALE_AFTER_HOURS = 2;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = self::STALE_AFTER_HOURS * 3600 - 60;

    public function __construct(public readonly int $exportId, public readonly int $companyId)
    {
        $this->onConnection('platform');
        $this->onQueue('platform');
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(TenantExportBuilder $builder, TenantExportService $exports): void
    {
        $export = TenantExport::query()
            ->whereKey($this->exportId)
            ->where('company_id', $this->companyId)
            ->where('status', TenantExport::QUEUED)
            ->first();

        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => TenantExport::RUNNING, 'started_at' => now()])->save();
        $company = Company::query()->findOrFail($this->companyId);
        $exports->complete($export, $company, $builder->build($export, $company));
    }

    public function failed(?Throwable $error): void
    {
        $export = TenantExport::query()->whereKey($this->exportId)->where('company_id', $this->companyId)->first();

        if ($export !== null) {
            app(TenantExportService::class)->fail(
                $export,
                $error,
                PlatformAdmin::query()->find($export->requested_by_platform_admin_id),
            );
        }
    }
}
