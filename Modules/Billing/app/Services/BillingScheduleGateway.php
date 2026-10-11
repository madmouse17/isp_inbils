<?php

namespace Modules\Billing\Services;

/**
 * Thin invokable seam for scheduled billing commands.
 * Keeps BillingService static API authoritative while allowing tests to force failures.
 */
class BillingScheduleGateway
{
    /**
     * @return array{created:int, skipped:int, rows:list<array<string, mixed>>}
     */
    public function generateForPeriod(string $period, bool $dryRun = false, ?int $companyId = null): array
    {
        return BillingService::generateForPeriod($period, $dryRun, $companyId);
    }

    public function checkOverdue(?int $companyId = null): void
    {
        BillingService::checkOverdue($companyId);
    }

    /**
     * @return list<array{id: int, code: string}>
     */
    public function suspendOverdue(int $companyId, bool $dryRun = false): array
    {
        return OverdueSuspensionService::suspendOverdue($companyId, $dryRun);
    }
}
