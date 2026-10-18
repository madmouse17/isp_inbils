<?php

namespace Modules\Billing\Services;

/**
 * Thin invokable seam for scheduled billing commands.
 * Keeps BillingService static API authoritative while allowing tests to force failures.
 */
class BillingScheduleGateway
{
    /**
     * @return array{created: int, skipped: int, failed: int, rows: list<array<string, mixed>>} `failed` counts the subscriptions that were reported and left out
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
     *
     * @throws \RuntimeException when a subscription could not be suspended; the others were
     */
    public function suspendOverdue(int $companyId, bool $dryRun = false): array
    {
        return OverdueSuspensionService::suspendOverdue($companyId, $dryRun);
    }
}
