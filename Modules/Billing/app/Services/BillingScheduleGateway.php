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
    public function generateForPeriod(string $period, bool $dryRun = false): array
    {
        return BillingService::generateForPeriod($period, $dryRun);
    }

    public function checkOverdue(): void
    {
        BillingService::checkOverdue();
    }
}
