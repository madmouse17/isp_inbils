<?php

namespace App\Jobs\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\PlatformAuditService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fills a company that platform provisioning just created with the sample ISP (DemoDataSeeder::seedFor). Six months
 * of history take minutes, so it never runs in the request: the `platform` queue has a worker with a one-hour timeout
 * (docs/operations/platform-queue.md). One job per company at a time, one try: a half-done seed rolls back as a
 * whole, and the operator sees the failure in the platform audit instead of a silent retry.
 *
 * Carries ids only: the failed-job reporter logs a job's properties.
 */
class SeedTenantDemoDataJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    /** Safety net for a lock whose job never reached a worker: a later dispatch for the company is accepted again. */
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $companyId,
        public readonly int $adminUserId,
        public readonly int $platformAdminId,
    ) {
        $this->onConnection('platform');
        $this->onQueue('platform');
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(DemoDataSeeder $seeder, PlatformAuditService $audit): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        $seeder->seedFor($company, User::query()->findOrFail($this->adminUserId));

        $audit->platform('tenant.demo_data_seeded', ['tenant_id' => $company->id, 'tenant_code' => $company->code], $company, $this->operator());
    }

    public function failed(?Throwable $e): void
    {
        app(PlatformAuditService::class)->platform('tenant.demo_data_failed', [
            'tenant_id' => $this->companyId,
            'error' => $e === null ? null : class_basename($e::class),
            'message' => $e === null ? null : Str::limit($e->getMessage(), 300, ''),
        ], Company::query()->find($this->companyId), $this->operator());
    }

    private function operator(): ?PlatformAdmin
    {
        return PlatformAdmin::query()->find($this->platformAdminId);
    }
}
